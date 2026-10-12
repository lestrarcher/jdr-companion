<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{Character, LanguageGrantRule, ExpertiseGrantRule};

final readonly class CharacterTrainingResolver
{
    public function __construct(
        private CharacterProficiencyResolver $proficiencies,
        private CharacterRaceMetadataResolver $raceMetadata,
        private CharacterTrainingReferences $references,
    ) {}
    public function applicable(Character $character, LanguageGrantRule|ExpertiseGrantRule $rule): bool
    {
        $rule->validate();
        if (!ReferenceVisibility::allows($rule, $character->getCampaign()->getOwner())) return false;
        if ($class = $rule->getCharacterClass()) return $character->getLevelInClass($class) >= $rule->getUnlockLevel();
        if ($rule instanceof ExpertiseGrantRule && ($subclass = $rule->getCharacterSubclass())) {
            return $character->getSubclassFor($subclass->getCharacterClass()) === $subclass
                && $character->getLevelInClass($subclass->getCharacterClass()) >= $rule->getUnlockLevel();
        }
        if ($character->getTotalLevel() < $rule->getUnlockLevel()) return false;
        if ($background = $rule->getCharacterBackground()) return $character->getBackground() === $background;
        if ($rule instanceof ExpertiseGrantRule) {
            if ($race = $rule->getCharacterRace()) return $character->getRace()?->inheritsFrom($race) ?? false;
            if ($feat = $rule->getFeat()) return $character->hasFeat($feat);
        }
        return false;
    }
    /** Runtime requirements use racial metadata exclusively, never racial language rules. */
    public function languageRequirements(Character $character, array $refs): array
    {
        $requirements = [];
        $languages = [];
        foreach ($refs['languages'] as $language) $languages[$language->getSlug()] = $language;
        if ($race = $character->getRace()) {
            $metadata = $this->raceMetadata->resolve($race);
            $fixed = [];
            foreach ($metadata['languages'] as $slug) {
                if (!isset($languages[$slug])) throw new \DomainException('Langue raciale inconnue ou invisible : '.$slug);
                $fixed[] = $languages[$slug];
            }
            $requirements['race:'.$race->getSlug()] = [
                'sourceKey' => 'race:'.$race->getSlug(), 'sourceType' => 'race', 'sourceId' => $race->getId(),
                'automatic' => $fixed, 'choiceCount' => $metadata['languageChoiceCount'],
                'options' => array_values(array_filter($languages, static fn ($language) => $language->getCategory() !== 'SECRET')),
            ];
        }
        foreach ($refs['languageRules'] as $rule) {
            if (!$this->applicable($character, $rule)) continue;
            $requirements[$rule->getImportKey()] = [
                'sourceKey' => $rule->getImportKey(), 'sourceType' => $rule->getCharacterClass() ? 'class' : 'background',
                'sourceId' => ($rule->getCharacterClass() ?? $rule->getCharacterBackground())->getId(),
                'automatic' => $rule->getChoiceCount() === 0 ? $rule->getOptions()->toArray() : [],
                'choiceCount' => $rule->getChoiceCount(),
                'options' => array_values(array_filter($rule->getOptions()->toArray(), static fn ($language) => $rule->getCharacterClass() !== null || $language->getCategory() !== 'SECRET')),
            ];
        }
        return $requirements;
    }
    public function resolve(Character $character, ?array $refs = null): array
    {
        $refs ??= $this->references->load($character);
        $proficiencies = $this->proficiencies->resolve($character);
        $languages = $expertises = [];
        foreach ($this->languageRequirements($character, $refs) as $key => $requirement) {
            $selected = $requirement['automatic'];
            if ($requirement['choiceCount'] > 0) {
                $selected = [];
                foreach ($character->getLanguageChoices() as $choice) {
                    if ($choice->getSourceKey() !== $key) continue;
                    if (!in_array($choice->getLanguage(), $requirement['options'], true) || in_array($choice->getLanguage(), $selected, true)) { $selected = []; break; }
                    $selected[] = $choice->getLanguage();
                }
                if (count($selected) !== $requirement['choiceCount']) $selected = [];
                $selected = [...$requirement['automatic'], ...$selected];
            }
            foreach ($selected as $language) {
                $languages[$language->getSlug()] ??= ['definition' => $language, 'sources' => []];
                $languages[$language->getSlug()]['sources'][] = $key;
            }
        }
        $rules = $refs['expertiseRules'];
        usort($rules, static fn ($a, $b) => [$a->getUnlockLevel(), $a->getImportKey()] <=> [$b->getUnlockLevel(), $b->getImportKey()]);
        foreach ($rules as $rule) {
            if (!$this->applicable($character, $rule)) continue;
            $selected = $rule->getOptions()->toArray();
            if ($rule->getChoiceCount() > 0) {
                $selected = [];
                foreach ($character->getExpertiseChoices() as $choice) {
                    if ($choice->getGrantRule() !== $rule) continue;
                    $definition = $choice->getProficiencyDefinition();
                    if (!$rule->getOptions()->contains($definition) || in_array($definition, $selected, true)
                        || isset($expertises[$definition->getSlug()]) || !isset($proficiencies[$definition->getSlug()])) continue 2;
                    $selected[] = $definition;
                }
                if (count($selected) !== $rule->getChoiceCount()) continue;
            }
            foreach ($selected as $definition) {
                if (!isset($proficiencies[$definition->getSlug()])) continue;
                $expertises[$definition->getSlug()] ??= ['definition' => $definition, 'sources' => []];
                $expertises[$definition->getSlug()]['sources'][] = $rule->getImportKey();
            }
        }
        foreach ($character->getManualGrants() as $grant) {
            if ($grant->getKind() === 'LANGUAGE' && ($language = $grant->getLanguage())
                && ReferenceVisibility::allows($language, $character->getCampaign()->getOwner())) {
                $languages[$language->getSlug()] ??= ['definition' => $language, 'sources' => []];
                $languages[$language->getSlug()]['sources'][] = 'manual:'.$grant->getId();
            }
            if ($grant->getKind() === 'EXPERTISE' && ($definition = $grant->getProficiencyDefinition())
                && isset($proficiencies[$definition->getSlug()]) && in_array($definition->getType()->value, ['SKILL', 'TOOL'], true)) {
                $expertises[$definition->getSlug()] ??= ['definition' => $definition, 'sources' => []];
                $expertises[$definition->getSlug()]['sources'][] = 'manual:'.$grant->getId();
            }
        }
        ksort($languages); ksort($expertises);
        return ['proficiencies' => $proficiencies, 'languages' => $languages, 'expertises' => $expertises];
    }
}

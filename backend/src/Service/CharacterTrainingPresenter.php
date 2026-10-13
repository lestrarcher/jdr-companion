<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{Character, ProficiencyGrantRule};

final readonly class CharacterTrainingPresenter
{
    public function __construct(private CharacterTrainingReferences $references, private CharacterTrainingResolver $resolver, private CharacterProficiencyResolver $proficiencies) {}
    /** Public profiles omit the GM audit log, while retaining effective manual grants. */
    public function resolved(Character $character, bool $includeManualGrantDetails = true): array
    {
        $resolved = $this->resolver->resolve($character);
        $output = [];
        foreach ($resolved as $kind => $entries) {
            $output[$kind] = [];
            foreach ($entries as $entry) {
                $definition = $entry['definition'];
                $item = ['id' => $definition->getId(), 'slug' => $definition->getSlug(), 'name' => $definition->getName()];
                if ($kind === 'proficiencies' || $kind === 'expertises') {
                    $item['type'] = $definition->getType()->value;
                }
                if ($kind === 'expertises') $item['proficiencyId'] = $definition->getId();
                if ($kind === 'proficiencies') {
                    $item['grants'] = array_map(fn ($r) => $this->proficiencyRule($r), $entry['grants']);
                    $item['manualGrantIds'] = array_map(static fn ($g) => $g->getId(), $entry['manualGrants'] ?? []);
                    $item['substitutions'] = array_map(static fn ($s) => ['ruleId' => $s->getGrantRule()->getId(), 'originalProficiencyId' => $s->getOriginal()->getId()], $entry['substitutions'] ?? []);
                } else $item['sources'] = $entry['sources'];
                $output[$kind][] = $item;
            }
        }
        if ($includeManualGrantDetails) $output['manualGrants'] = array_map(static fn ($g) => [
            'id' => $g->getId(), 'kind' => $g->getKind(), 'proficiencyId' => $g->getProficiencyDefinition()?->getId(),
            'languageSlug' => $g->getLanguage()?->getSlug(), 'reason' => $g->getReason(),
            'grantedById' => $g->getGrantedBy()->getId(), 'createdAt' => $g->getCreatedAt()->format(DATE_ATOM),
        ], $character->getManualGrants()->toArray());
        return $output;
    }
    public function options(Character $character, bool $includeManualGrantDetails = true): array
    {
        $refs = $this->references->load($character);
        $effective = $this->resolver->resolve($character, $refs);
        $rules = [];
        foreach ($refs['proficiencyRules'] as $rule) if ($this->proficiencies->isApplicable($character, $rule)) $rules[] = $this->proficiencyRule($rule);
        $languages = [];
        foreach ($this->resolver->languageRequirements($character, $refs) as $requirement) {
            $languages[] = [
                'sourceKey' => $requirement['sourceKey'], 'sourceType' => $requirement['sourceType'], 'sourceId' => $requirement['sourceId'],
                'automaticSlugs' => array_map(static fn ($l) => $l->getSlug(), $requirement['automatic']),
                'choiceCount' => $requirement['choiceCount'],
                // Keep this source's resolved selections visible when refreshing the editor.
                'optionSlugs' => array_values(array_map(static fn ($l) => $l->getSlug(), array_filter($requirement['options'], static fn ($l) => !isset($effective['languages'][$l->getSlug()])
                    || ($requirement['choiceCount'] > 0 && !in_array($l, $requirement['automatic'], true)
                        && in_array($requirement['sourceKey'], $effective['languages'][$l->getSlug()]['sources'], true))))),
            ];
        }
        $substitutions = [];
        foreach ($this->proficiencies->resolveBase($character) as $entry) {
            $definition = $entry['definition'];
            if (count($entry['grants']) < 2 || !in_array($definition->getType()->value, ['SKILL','TOOL'], true)) continue;
            foreach ($entry['grants'] as $rule) if ($rule->getCharacterBackground()) {
                $substitutions[] = ['ruleId' => $rule->getId(), 'originalProficiencyId' => $definition->getId(),
                    'optionIds' => array_values(array_map(static fn ($d) => $d->getId(), array_filter($refs['proficiencies'],
                        static fn ($d) => $d->getType() === $definition->getType() && !isset($effective['proficiencies'][$d->getSlug()]))))];
            }
        }
        $expertises = [];
        foreach ($refs['expertiseRules'] as $rule) {
            if (!$this->resolver->applicable($character, $rule)) continue;
            $expertises[] = ['ruleId' => $rule->getId(), 'key' => $rule->getImportKey(), 'label' => $rule->getLabel(),
                'unlockLevel' => $rule->getUnlockLevel(), 'choiceCount' => $rule->getChoiceCount(),
                'optionIds' => array_values(array_map(static fn ($d) => $d->getId(), array_filter($rule->getOptions()->toArray(),
                    static fn ($d) => isset($effective['proficiencies'][$d->getSlug()]) && (!isset($effective['expertises'][$d->getSlug()])
                        || in_array($rule->getImportKey(), $effective['expertises'][$d->getSlug()]['sources'], true)))))];
        }
        $isRanger = false;
        foreach ($character->getClassLevels() as $level) if ($level->getCharacterClass()->getSlug() === 'ranger') $isRanger = true;
        $optionIds = [];
        foreach ([...$rules, ...$expertises] as $rule) foreach ($rule['optionIds'] as $id) $optionIds[$id] = true;
        $languageSlugs = [];
        foreach ($languages as $rule) foreach ([...$rule['optionSlugs'], ...$rule['automaticSlugs']] as $slug) $languageSlugs[$slug] = true;
        $definitions = [
            'proficiencies' => array_values(array_map(static fn ($d) => ['id'=>$d->getId(), 'slug'=>$d->getSlug(), 'name'=>$d->getName(), 'type'=>$d->getType()->value],
                array_filter($refs['proficiencies'], static fn ($d) => isset($optionIds[$d->getId()])))),
            'languages' => array_values(array_map(static fn ($d) => ['id'=>$d->getId(), 'slug'=>$d->getSlug(), 'name'=>$d->getName()],
                array_filter($refs['languages'], static fn ($d) => isset($languageSlugs[$d->getSlug()])))),
        ];
        return ['proficiencyRules' => $rules, 'languageRules' => $languages, 'substitutions' => $substitutions,
            'definitions' => $definitions,
            'choices' => $this->choices($character),
            'expertiseRules' => $expertises, 'resolved' => $this->resolved($character, $includeManualGrantDetails),
            'notImplemented' => $isRanger ? ['favored-enemy-language: manual GM grant required'] : []];
    }
    /** Active requirements only. Historical choices remain stored but cannot satisfy another source. */
    public function choices(Character $character): array
    {
        $refs = $this->references->load($character);
        $effective = $this->resolver->resolve($character, $refs);
        $result = [];
        foreach (['proficiency' => $refs['proficiencyRules'], 'expertise' => $refs['expertiseRules']] as $kind => $rules) {
            foreach ($rules as $rule) {
                if ($rule->getChoiceCount() === 0 || !($kind === 'proficiency' ? $this->proficiencies->isApplicable($character, $rule) : $this->resolver->applicable($character, $rule))) continue;
                $selected = [];
                foreach ($kind === 'proficiency' ? $character->getProficiencyChoices() : $character->getExpertiseChoices() as $choice) {
                    if ($choice->getGrantRule() === $rule) $selected[] = $choice->getProficiencyDefinition()->getId();
                }
                $count = count(array_filter($effective[$kind === 'proficiency' ? 'proficiencies' : 'expertises'],
                    static fn ($entry) => $kind === 'proficiency' ? in_array($rule, $entry['grants'], true) : in_array($rule->getImportKey(), $entry['sources'], true)));
                $result[] = $this->choiceState($kind.':'.$rule->getId(), $kind, $rule->getChoiceCount(), $selected, $count) + ['ruleId' => $rule->getId()];
            }
        }
        foreach ($this->resolver->languageRequirements($character, $refs) as $key => $requirement) {
            if ($requirement['choiceCount'] === 0) continue;
            $selected = [];
            foreach ($character->getLanguageChoices() as $choice) if ($choice->getSourceKey() === $key) $selected[] = $choice->getLanguage()->getSlug();
            $count = count(array_filter($effective['languages'], static fn ($entry) => in_array($key, $entry['sources'], true)
                && !in_array($entry['definition'], $requirement['automatic'], true)));
            $result[] = $this->choiceState('language:'.$key, 'language', $requirement['choiceCount'], $selected, $count) + ['sourceKey' => $key];
        }
        return $result;
    }

    private function choiceState(string $key, string $kind, int $required, array $selected, int $effective): array
    {
        return ['key' => $key, 'kind' => $kind, 'choiceCount' => $required, 'selected' => $selected,
            'status' => $effective === $required ? 'complete' : ($selected === [] ? 'missing' : 'invalid')];
    }
    private function proficiencyRule(ProficiencyGrantRule $rule): array
    {
        $type = 'feat'; $source = $rule->getFeat();
        foreach (['race' => $rule->getCharacterRace(), 'class' => $rule->getCharacterClass(), 'subclass' => $rule->getCharacterSubclass(), 'background' => $rule->getCharacterBackground()] as $key => $value) {
            if ($value) { $type = $key; $source = $value; break; }
        }
        return ['ruleId' => $rule->getId(), 'key' => $rule->getImportKey(), 'label' => $rule->getLabel(),
            'sourceType' => $type, 'sourceId' => $source?->getId(), 'unlockLevel' => $rule->getUnlockLevel(),
            'choiceCount' => $rule->getChoiceCount(), 'acquisitionMode' => $rule->getAcquisitionMode()->value,
            'optionIds' => array_map(static fn ($d) => $d->getId(), $rule->getOptions()->toArray())];
    }
}


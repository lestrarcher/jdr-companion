<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\ProficiencyDefinition;
use App\Entity\ProficiencyGrantRule;
use App\Enum\ProficiencyAcquisitionMode;
use App\Repository\ProficiencyGrantRuleRepository;

final readonly class CharacterProficiencyResolver
{
    public function __construct(private ProficiencyGrantRuleRepository $ruleRepository)
    {
    }

    /**
     * One effective proficiency per slug, with every granting rule retained.
     * Incomplete/obsolete choice batches grant nothing, without deleting history.
     * No stacking, substitution or persistence is performed here.
     * @return array<string, array{definition: ProficiencyDefinition, grants: list<ProficiencyGrantRule>}>
     */
    public function resolve(Character $character): array
    {
        $result = $this->resolveBase($character);
        $seen = [];
        foreach ($character->getProficiencySubstitutions() as $substitution) {
            $rule = $substitution->getGrantRule();
            $from = $substitution->getOriginal();
            $to = $substitution->getReplacement();
            $key = spl_object_id($rule).':'.$from->getSlug();
            if (isset($seen[$key]) || $rule->getCharacterBackground() === null
                || !in_array($from->getType()->value, ['SKILL', 'TOOL'], true) || $from->getType() !== $to->getType()
                || count($result[$from->getSlug()]['grants'] ?? []) < 2
                || !in_array($rule, $result[$from->getSlug()]['grants'] ?? [], true)
                || isset($result[$to->getSlug()]) || !ReferenceVisibility::allows($to, $character->getCampaign()->getOwner())) continue;
            $seen[$key] = true;
            $result[$from->getSlug()]['grants'] = array_values(array_filter($result[$from->getSlug()]['grants'], static fn ($grant) => $grant !== $rule));
            $result[$to->getSlug()] = ['definition' => $to, 'grants' => [$rule], 'substitutions' => [$substitution]];
        }
        foreach ($character->getManualGrants() as $grant) {
            $definition = $grant->getProficiencyDefinition();
            if ($grant->getKind() !== 'PROFICIENCY' || $definition === null || !ReferenceVisibility::allows($definition, $character->getCampaign()->getOwner())) continue;
            $result[$definition->getSlug()] ??= ['definition' => $definition, 'grants' => []];
            $result[$definition->getSlug()]['manualGrants'][] = $grant;
        }
        ksort($result);
        return $result;
    }

    /** Grants before explicit background substitutions or manual grants. */
    public function resolveBase(Character $character): array
    {
        $result = [];
        $owner = $character->getCampaign()->getOwner();
        foreach ($this->ruleRepository->findVisibleForOwner($owner) as $rule) {
            if (!ReferenceVisibility::allows($rule, $owner)) {
                continue;
            }
            $rule->validateRule();
            if (!$this->isApplicable($character, $rule)) {
                continue;
            }

            $definitions = $rule->getOptions()->toArray();
            if ($rule->getChoiceCount() > 0) {
                $definitions = [];
                foreach ($character->getProficiencyChoices() as $choice) {
                    if ($choice->getGrantRule() === $rule) {
                        $definition = $choice->getProficiencyDefinition();
                        if (!$rule->getOptions()->contains($definition) || in_array($definition, $definitions, true)) {
                            continue 2;
                        }
                        $definitions[] = $definition;
                    }
                }
                if (count($definitions) !== $rule->getChoiceCount()) {
                    continue;
                }
            }

            foreach ($definitions as $definition) {
                $slug = $definition->getSlug();
                $result[$slug] ??= ['definition' => $definition, 'grants' => []];
                $result[$slug]['grants'][] = $rule;
            }
        }
        ksort($result);

        return $result;
    }

    public function isApplicable(Character $character, ProficiencyGrantRule $rule): bool
    {
        if (($class = $rule->getCharacterClass()) !== null) {
            if ($character->getLevelInClass($class) < $rule->getUnlockLevel()) {
                return false;
            }
            if ($rule->getAcquisitionMode() === ProficiencyAcquisitionMode::Any) {
                return true;
            }
            foreach ($character->getClassLevels() as $level) {
                if ($level->getPosition() === 1) {
                    $starting = $level->getCharacterClass() === $class;

                    return $rule->getAcquisitionMode() === ProficiencyAcquisitionMode::StartingClass ? $starting : !$starting;
                }
            }
            // Never guess the initial class from collection order or incomplete history.
            return false;
        }
        if (($subclass = $rule->getCharacterSubclass()) !== null) {
            return $character->getSubclassFor($subclass->getCharacterClass()) === $subclass
                && $character->getLevelInClass($subclass->getCharacterClass()) >= $rule->getUnlockLevel();
        }
        if ($character->getTotalLevel() < $rule->getUnlockLevel()) {
            return false;
        }
        if (($race = $rule->getCharacterRace()) !== null) {
            for ($current = $character->getRace(); $current !== null; $current = $current->getParentRace()) {
                if ($current === $race) {
                    return true;
                }
            }

            return false;
        }

        if (($background = $rule->getCharacterBackground()) !== null) {
            return $character->getBackground() === $background;
        }

        return $rule->getFeat() !== null && $character->hasFeat($rule->getFeat());
    }
}

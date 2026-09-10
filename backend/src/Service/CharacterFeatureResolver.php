<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterFeatureRule;
use App\Entity\CharacterRace;
use App\Repository\CharacterFeatureRuleRepository;

final readonly class CharacterFeatureResolver
{
    public function __construct(
        private CharacterFeatureRuleRepository $ruleRepository,
    ) {
    }

    /**
     * Retourne une seule règle applicable par capacité.
     *
     * Les règles historiques conservent leur sélection par niveau.
     * Pour une même progression, le plus grand seuil applicable est conservé.
     * Entre une progression et une autre source, la première règle est conservée.
     *
     * @param array<string, int> $progressionValues Valeurs courantes par slug.
     *
     * @return array<string, CharacterFeatureRule>
     */
    public function resolve(
        Character $character,
        array $progressionValues = [],
    ): array {
        $resolvedRules = [];

        foreach ($this->ruleRepository->findOrdered() as $rule) {
            if (!$this->isRuleApplicable($character, $rule, $progressionValues)) {
                continue;
            }

            $slug = $rule->getFeatureDefinition()->getSlug();
            $currentRule = $resolvedRules[$slug] ?? null;

            if (
                !$currentRule instanceof CharacterFeatureRule
                || $this->shouldReplaceRule($currentRule, $rule)
            ) {
                $resolvedRules[$slug] = $rule;
            }
        }

        uasort(
            $resolvedRules,
            static function (
                CharacterFeatureRule $first,
                CharacterFeatureRule $second,
            ): int {
                $orderComparison = $first->getDisplayOrder()
                    <=> $second->getDisplayOrder();

                if ($orderComparison !== 0) {
                    return $orderComparison;
                }

                return $first
                    ->getFeatureDefinition()
                    ->getName()
                    <=> $second
                        ->getFeatureDefinition()
                        ->getName();
            },
        );

        return $resolvedRules;
    }

    private function shouldReplaceRule(
        CharacterFeatureRule $currentRule,
        CharacterFeatureRule $rule,
    ): bool {
        $progression = $rule->getProgressionDefinition();
        $currentProgression = $currentRule->getProgressionDefinition();

        if ($progression !== null || $currentProgression !== null) {
            return $progression !== null
                && $progression === $currentProgression
                && $rule->getProgressionThreshold()
                    > $currentRule->getProgressionThreshold();
        }

        return $rule->getUnlockLevel() > $currentRule->getUnlockLevel();
    }

    /**
     * @param array<string, int> $progressionValues
     */
    private function isRuleApplicable(
        Character $character,
        CharacterFeatureRule $rule,
        array $progressionValues,
    ): bool {
        $progression = $rule->getProgressionDefinition();

        if ($progression !== null) {
            $slug = $progression->getSlug();
            $threshold = $rule->getProgressionThreshold();

            return $character->hasProgression($progression)
                && $threshold !== null
                && array_key_exists($slug, $progressionValues)
                && $progressionValues[$slug] >= $threshold;
        }

        $characterClass = $rule->getCharacterClass();

        if ($characterClass !== null) {
            return $character->getLevelInClass($characterClass)
                >= $rule->getUnlockLevel();
        }

        $subclass = $rule->getCharacterSubclass();

        if ($subclass !== null) {
            $characterClass = $subclass->getCharacterClass();

            return $character->getLevelInClass($characterClass)
                >= $rule->getUnlockLevel()
                && $character->getSubclassFor($characterClass) === $subclass;
        }

        $race = $rule->getCharacterRace();

        if ($race !== null) {
            return $character->getTotalLevel() >= $rule->getUnlockLevel()
                && $this->hasRaceOrParent($character->getRace(), $race);
        }

        $feat = $rule->getFeat();

        return $feat !== null
            && $character->getTotalLevel() >= $rule->getUnlockLevel()
            && $character->hasFeat($feat);
    }

    private function hasRaceOrParent(
        ?CharacterRace $characterRace,
        CharacterRace $expectedRace,
    ): bool {
        while ($characterRace !== null) {
            if ($characterRace === $expectedRace) {
                return true;
            }

            $characterRace = $characterRace->getParentRace();
        }

        return false;
    }
}

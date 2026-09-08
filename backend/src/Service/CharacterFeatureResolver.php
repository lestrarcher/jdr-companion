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
     * Lorsqu’une même capacité possède plusieurs paliers,
     * la règle ayant le niveau de déblocage le plus élevé est conservée.
     *
     * @return array<string, CharacterFeatureRule>
     */
    public function resolve(Character $character): array
    {
        $resolvedRules = [];

        foreach ($this->ruleRepository->findOrdered() as $rule) {
            if (!$this->isRuleApplicable($character, $rule)) {
                continue;
            }

            $slug = $rule->getFeatureDefinition()->getSlug();
            $currentRule = $resolvedRules[$slug] ?? null;

            if (
                !$currentRule instanceof CharacterFeatureRule
                || $rule->getUnlockLevel() > $currentRule->getUnlockLevel()
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

    private function isRuleApplicable(
        Character $character,
        CharacterFeatureRule $rule,
    ): bool {
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

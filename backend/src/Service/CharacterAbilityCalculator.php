<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\EffectiveAbilityScore;
use App\Dto\LevelAdvancementSelection;
use App\Entity\Character;
use App\Entity\MagicItemAbilityEffect;
use App\Enum\Ability;
use App\Enum\AbilityAdjustmentOperation;
use App\Enum\AbilityEffectOperation;

final class CharacterAbilityCalculator
{
    private const ABSOLUTE_MINIMUM = 1;
    private const ABSOLUTE_MAXIMUM = 30;

    /** Statistics for a spell's own casting ability, independent of class policy. */
    public function spellStatistics(Character $character, Ability $ability): array
    {
        // Avoid getAbilityScore() when missing: its legacy fallback creates a score.
        foreach ($character->getAbilityScores() as $score) {
            if ($score->getAbility() !== $ability) continue;
            $attack = $character->getProficiencyBonus()
                + $this->calculate($character, $ability)->modifier();
            return ['spellSaveDc' => 8 + $attack, 'spellAttackBonus' => $attack];
        }
        return ['spellSaveDc' => null, 'spellAttackBonus' => null];
    }

    /**
     * @return array<string, EffectiveAbilityScore>
     */
    public function calculateAll(Character $character): array
    {
        $results = [];

        foreach (Ability::cases() as $ability) {
            $results[$ability->value] = $this->calculate($character, $ability);
        }

        return $results;
    }

    public function calculate(Character $character, Ability $ability, ?LevelAdvancementSelection $advancement = null): EffectiveAbilityScore
    {
        $abilityScore = $character->getAbilityScore($ability);

        $baseValue = $abilityScore->getBaseValue();

        $effectiveValue = $this->calculatePermanentValue($character, $ability, $advancement);
        $minimumValue = null;

        foreach ($character->getMagicItems() as $ownedItem) {
            if (!$ownedItem->isEffectActive()) {
                continue;
            }

            foreach ($ownedItem->getMagicItem()->getAbilityEffects() as $effect) {
                if ($effect->getAbility() !== $ability) {
                    continue;
                }

                if ($effect->getOperation() === AbilityEffectOperation::Bonus) {
                    $effectiveValue = $this->applyBonus($effectiveValue, $effect);
                    continue;
                }

                if ($effect->getOperation() === AbilityEffectOperation::Minimum) {
                    $minimumValue = max($minimumValue ?? 0, $effect->getValue());
                }

                /*
                 * PermanentIncrease n’est pas appliqué ici.
                 *
                 * Un tome ou un manuel modifiera
                 * directement la valeur de base et son
                 * maximum lorsqu’il sera consommé.
                 */
            }
        }

        if ($minimumValue !== null) {
            $effectiveValue = max(
                $effectiveValue,
                $minimumValue,
            );
        }

        $effectiveValue = max(
            self::ABSOLUTE_MINIMUM,
            min(
                self::ABSOLUTE_MAXIMUM,
                $effectiveValue,
            ),
        );

        return new EffectiveAbilityScore(
            ability: $ability,
            baseValue: $baseValue,
            effectiveValue:
                $effectiveValue,
            maximumValue:
                $abilityScore
                    ->getMaximumValue(),
        );
    }

    /** Permanent score before conditional equipment effects, used to validate ASIs. */
    public function calculatePermanentValue(Character $character, Ability $ability, ?LevelAdvancementSelection $advancement = null): int
    {
        $baseValue = $character->getAbilityScore($ability)->getBaseValue();
        $effectiveValue = $baseValue;

        $race = $character->getRace();

        if ($race !== null) {
            foreach ($race->getInheritedAbilityModifiers() as $modifier) {
                if ($modifier->requiresChoice()) {
                    continue;
                }

                if ($modifier->getAbility() === $ability) {
                    $effectiveValue += $modifier->getValue();
                }
            }
        }

        foreach ($character->getRaceAbilityChoices() as $choice) {
            if ($choice->getAbility() === $ability) {
                $effectiveValue += $choice->getValue();
            }
        }

        /*
        * Les dons sont des améliorations permanentes
        * du personnage. Ils sont appliqués avant les
        * effets temporaires ou conditionnels des objets.
        */
        foreach ($character->getFeats() as $characterFeat) {
            if ($characterFeat->getChosenAbility() !== $ability) {
                continue;
            }

            $effectiveValue += $characterFeat->getAbilityIncrease();
        }

        // Project a level-up without attaching a feat or adjustment to the character.
        if ($advancement?->getFeatAbility() === $ability) {
            $effectiveValue += $advancement->getFeat()?->getChosenAbilityIncrease() ?? 0;
        }
        $effectiveValue = $this->applyPermanentAdjustments($character, $ability, $effectiveValue);
        foreach ($advancement?->getAbilityIncreases() ?? [] as $increase) {
            if ($increase['ability'] === $ability) $effectiveValue += $increase['value'];
        }

        return $effectiveValue;
    }

    private function applyBonus(
        int $currentValue,
        MagicItemAbilityEffect $effect,
    ): int {
        $newValue =
            $currentValue
            + $effect->getValue();

        $scoreCap =
            $effect->getScoreCap();

        if (
            $scoreCap !== null
            && $effect->getValue() > 0
        ) {
            $newValue = min(
                $newValue,
                $scoreCap,
            );
        }

        return $newValue;
    }

    private function applyPermanentAdjustments(Character $character, Ability $ability, int $currentValue): int
    {
        foreach ($character->getAbilityAdjustments() as $adjustment) {
            if ($adjustment->getAbility() === $ability) {
                $currentValue = $adjustment->apply($currentValue);
            }
        }

        return $currentValue;
    }
}

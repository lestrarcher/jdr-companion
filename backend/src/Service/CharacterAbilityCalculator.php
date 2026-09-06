<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\EffectiveAbilityScore;
use App\Entity\Character;
use App\Entity\MagicItemAbilityEffect;
use App\Enum\Ability;
use App\Enum\AbilityEffectOperation;

final class CharacterAbilityCalculator
{
    private const ABSOLUTE_MINIMUM = 1;
    private const ABSOLUTE_MAXIMUM = 30;

    /**
     * @return array<string, EffectiveAbilityScore>
     */
    public function calculateAll(
        Character $character,
    ): array {
        $results = [];

        foreach (
            Ability::cases()
            as $ability
        ) {
            $results[$ability->value] =
                $this->calculate(
                    $character,
                    $ability,
                );
        }

        return $results;
    }

    public function calculate(
        Character $character,
        Ability $ability,
    ): EffectiveAbilityScore {
        $abilityScore =
            $character->getAbilityScore(
                $ability,
            );

        $baseValue =
            $abilityScore->getBaseValue();

        $effectiveValue = $baseValue;
        $minimumValue = null;

        foreach (
            $character->getMagicItems()
            as $ownedItem
        ) {
            if (
                !$ownedItem->isEffectActive()
            ) {
                continue;
            }

            foreach (
                $ownedItem
                    ->getMagicItem()
                    ->getAbilityEffects()
                as $effect
            ) {
                if (
                    $effect->getAbility()
                    !== $ability
                ) {
                    continue;
                }

                if (
                    $effect->getOperation()
                    ===
                    AbilityEffectOperation::Bonus
                ) {
                    $effectiveValue =
                        $this->applyBonus(
                            $effectiveValue,
                            $effect,
                        );

                    continue;
                }

                if (
                    $effect->getOperation()
                    ===
                    AbilityEffectOperation::Minimum
                ) {
                    $minimumValue = max(
                        $minimumValue ?? 0,
                        $effect->getValue(),
                    );
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
}

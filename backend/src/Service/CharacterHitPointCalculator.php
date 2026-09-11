<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ResolvedCharacterHitPoints;
use App\Entity\Character;
use App\Enum\Ability;

final readonly class CharacterHitPointCalculator
{
    public function __construct(
        private CharacterAbilityCalculator $abilityCalculator,
    ) {
    }

    public function calculate(Character $character): ResolvedCharacterHitPoints
    {
        $baseValue = 0;
        $total = 0;
        $missingLevelPositions = [];
        $constitutionModifier = $this->abilityCalculator
            ->calculate($character, Ability::Constitution)
            ->modifier();

        foreach ($character->getClassLevels() as $level) {
            $gain = $level->getHitPointGain();

            if ($gain === null) {
                $missingLevelPositions[] = $level->getPosition();
                continue;
            }

            $baseValue += $gain;
            $total += max(1, $gain + $constitutionModifier);
        }

        $constitutionBonus = $constitutionModifier * $character->getTotalLevel();

        /*
         * Tant qu’un ancien niveau ne possède pas son gain brut,
         * on refuse d’annoncer un maximum potentiellement faux.
         */
        $maximumValue = $missingLevelPositions === []
            ? $total
            : null;

        return new ResolvedCharacterHitPoints(
            maximumValue: $maximumValue,
            baseValue: $baseValue,
            constitutionModifier: $constitutionModifier,
            constitutionBonus: $constitutionBonus,
            missingLevelPositions: $missingLevelPositions,
        );
    }
}

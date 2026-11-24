<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ResolvedCharacterHitPoints;
use App\Entity\Character;
use App\Entity\CharacterClassLevel;
use App\Enum\Ability;
use App\Enum\FeatureActivationType;

final readonly class CharacterHitPointCalculator
{
    public function __construct(
        private CharacterAbilityCalculator $abilityCalculator,
        // Symfony injects the shared resolver; optional only for legacy base-calculation fixtures.
        private ?CharacterFeatureResolver $featureResolver = null,
    ) {
    }

    /** @param array<string, int> $progressionValues Explicit session context, never looked up here. */
    public function calculate(Character $character, array $progressionValues = []): ResolvedCharacterHitPoints
    {
        $baseValue = 0;
        $total = 0;
        $missingLevelPositions = [];
        $constitutionModifier = $this->abilityCalculator
            ->calculate($character, Ability::Constitution, progressionValues: $progressionValues)
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
        $featureBonus = 0;
        $contributions = [];
        // resolve() returns one acquired rule per feature, including selected options.
        foreach ($this->featureResolver?->resolve($character, $progressionValues) ?? [] as $rule) {
            $feature = $rule->getFeatureDefinition();
            if ($feature->getActivationType() !== FeatureActivationType::Passive) {
                continue;
            }
            foreach ($feature->getHitPointEffects() ?? [] as $effect) {
                $levels = match ($effect['type']) {
                    'fixed' => 1,
                    'per-total-level' => $character->getTotalLevel(),
                    'per-class-level' => count(array_filter($character->getClassLevels()->toArray(),
                        static fn (CharacterClassLevel $level): bool => $level->getCharacterClass()->getSlug() === $effect['classSlug'])),
                };
                $value = $effect['value'] * $levels;
                $featureBonus += $value;
                $contributions[] = [
                    'featureSlug' => $feature->getSlug(),
                    'key' => $effect['key'],
                    'label' => $effect['label'],
                    'value' => $value,
                    'type' => $effect['type'],
                    'unitValue' => $effect['value'],
                    'classSlug' => $effect['classSlug'] ?? null,
                ];
            }
        }

        /*
         * Tant qu’un ancien niveau ne possède pas son gain brut,
         * on refuse d’annoncer un maximum potentiellement faux.
         */
        $maximumValue = $missingLevelPositions === []
            ? $total + $featureBonus
            : null;

        return new ResolvedCharacterHitPoints(
            maximumValue: $maximumValue,
            baseValue: $baseValue,
            constitutionModifier: $constitutionModifier,
            constitutionBonus: $constitutionBonus,
            missingLevelPositions: $missingLevelPositions,
            featureBonus: $featureBonus,
            featureContributions: $contributions,
        );
    }
}

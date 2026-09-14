<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;

final readonly class CharacterHitPointStateService
{
    public function __construct(
        private CharacterHitPointCalculator $hitPointCalculator,
    ) {
    }

    public function baseMaximum(Character $character): ?int
    {
        $hitPoints = $this->hitPointCalculator->calculate($character);

        return $hitPoints->isComplete()
            ? $hitPoints->maximumValue
            : null;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function maximumAdjustment(array $state): int
    {
        return (int) ($state['hitPoints']['maximumAdjustment'] ?? 0);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function effectiveMaximum(
        Character $character,
        array $state,
    ): ?int {
        $baseMaximum = $this->baseMaximum($character);

        if ($baseMaximum === null) {
            return null;
        }

        return max(
            0,
            $baseMaximum + $this->maximumAdjustment($state),
        );
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    public function adjustMaximum(
        Character $character,
        array $state,
        int $delta,
    ): array {
        if ($delta === 0) {
            return $state;
        }

        $baseMaximum = $this->baseMaximum($character);

        if ($baseMaximum === null) {
            throw new \DomainException(
                'Impossible de modifier le maximum de PV : calcul des PV incomplet.',
            );
        }

        $currentAdjustment = $this->maximumAdjustment($state);
        $newAdjustment = $currentAdjustment + $delta;
        $newMaximum = $baseMaximum + $newAdjustment;

        if ($newMaximum < 0) {
            throw new \DomainException(
                'Le maximum de PV ne peut pas être inférieur à 0.',
            );
        }

        $state['hitPoints']['maximumAdjustment'] = $newAdjustment;

        $current = (int) ($state['hitPoints']['current'] ?? 0);

        if ($current > $newMaximum) {
            $state['hitPoints']['current'] = $newMaximum;
        }

        return $state;
    }
}

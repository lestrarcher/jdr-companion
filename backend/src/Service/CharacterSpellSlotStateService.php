<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;

final readonly class CharacterSpellSlotStateService
{
    public function __construct(private CharacterSpellSlotCalculator $spellSlotCalculator)
    {
    }

    /**
     * Returns session pool maxima keyed by spell level, including exhausted temporary pools.
     * Missing or invalid bonuses are ignored; current values never determine pool existence.
     *
     * @param array<string, mixed> $state
     * @return array<int, int> Natural levels union levels with a positive bonus, sorted by level.
     */
    public function effectiveMaximums(Character $character, array $state): array
    {
        $naturalMaximums = $this->spellSlotCalculator->calculate($character);
        $maximums = $naturalMaximums;
        $resources = $state['resources'] ?? [];

        if (!is_array($resources)) {
            return $maximums;
        }

        foreach ($resources as $resource) {
            if (!is_array($resource)) {
                continue;
            }

            $id = $resource['id'] ?? null;
            $bonus = $resource['flexibleCastingBonus'] ?? 0;

            if (!is_string($id) || preg_match('/\Aspell-slot-([1-9])\z/', $id, $matches) !== 1) {
                continue;
            }

            if (!is_int($bonus) || $bonus <= 0) {
                continue;
            }

            $level = (int) $matches[1];
            $maximums[$level] = ($naturalMaximums[$level] ?? 0) + $bonus;
        }

        ksort($maximums, SORT_NUMERIC);

        return $maximums;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\BeastRepository;

/** Lecture des attaques naturelles structurées ; les valeurs ne sont jamais recalculées sur le druide. */
final readonly class BeastNaturalWeaponCatalogue
{
    public function __construct(private BeastRepository $beasts) {}

    /** @return list<array<string, mixed>> */
    public function forBeast(int $beastId): array
    {
        $beast = $this->beasts->find($beastId);
        return $beast ? array_values(array_map(fn ($weapon) => $weapon->toArray(), $beast->getNaturalWeapons()->toArray())) : [];
    }
}

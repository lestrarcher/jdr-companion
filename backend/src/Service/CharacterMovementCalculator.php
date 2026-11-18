<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Enum\Ability;

/** Permanent movement modifiers from D&D 2014; no spells or temporary conditions. */
final readonly class CharacterMovementCalculator
{
    public function __construct(
        private CharacterRaceMetadataResolver $races,
        private CharacterAbilityCalculator $abilities,
    ) {}

    /** @param list<string> $featureSlugs */
    public function calculate(Character $character, array $featureSlugs): ?array
    {
        $race = $character->getRace();
        if ($race === null) return null;
        $movement = $this->races->resolve($race);
        if ($movement['walkingSpeed'] === null) return $movement;

        $levels = [];
        foreach ($character->getClassLevels() as $level) {
            $slug = $level->getCharacterClass()->getSlug();
            $levels[$slug] = ($levels[$slug] ?? 0) + 1;
        }
        $has = static fn (string $slug): bool => in_array($slug, $featureSlugs, true);
        $featSlugs = [];
        foreach ($character->getFeats() as $owned) $featSlugs[] = $owned->getFeat()->getSlug();

        $armor = $shield = false;
        $heavy = false;
        $strengthRequirement = null;
        foreach ($character->getMagicItems() as $item) {
            if (!$item->isEquipped()) continue;
            $definition = $item->getMagicItem();
            if ($definition === null) continue;
            if ($definition->getCategory() === 'shield') $shield = true;
            if ($definition->getCategory() !== 'armor') continue;
            $armor = true;
            $heavy = $definition->getArmorType() === 'heavy';
            $strengthRequirement = $definition->getArmorStrengthRequirement();
        }

        $bonus = 0.0;
        if (($levels['barbarian'] ?? 0) >= 5 && $has('deplacement-rapide') && !$heavy) $bonus += 3;
        $monk = $levels['monk'] ?? 0;
        if ($monk >= 2 && $has('unarmored-movement') && !$armor && !$shield) {
            $bonus += $monk >= 18 ? 9 : ($monk >= 14 ? 7.5 : ($monk >= 10 ? 6 : ($monk >= 6 ? 4.5 : 3)));
        }
        if (in_array('mobile', $featSlugs, true)) $bonus += 3;
        if (($levels['rogue'] ?? 0) >= 9 && $has('scout-superior-mobility')) {
            $bonus += 3;
            foreach (['swim', 'climb'] as $mode) {
                if (isset($movement['movementSpeeds'][$mode])) $movement['movementSpeeds'][$mode] += 3;
            }
        }
        // Dwarves ignore the heavy-armor Strength speed penalty (2014).
        $isDwarf = $race->getSlug() === 'dwarf' || $race->getParentRace()?->getSlug() === 'dwarf';
        $heavyArmorPenalty = ($heavy && !$isDwarf && $strengthRequirement !== null
            && $this->abilities->calculate($character, Ability::Strength)->effectiveValue < $strengthRequirement)
            ? 3.0 : 0.0;

        $movement['walkingSpeed'] = max(0, $movement['walkingSpeed'] + $bonus - $heavyArmorPenalty);
        // A general speed penalty also applies to existing swim, climb, fly and burrow speeds.
        // Do not create movement modes the character does not possess.
        foreach ($movement['movementSpeeds'] ?? [] as $mode => $speed) {
            $movement['movementSpeeds'][$mode] = max(0, $speed - $heavyArmorPenalty);
        }
        return $movement;
    }
}

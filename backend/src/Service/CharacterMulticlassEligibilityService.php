<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterClass;
use App\Enum\Ability;

final readonly class CharacterMulticlassEligibilityService
{
    private const REQUIREMENTS = [
        'barbarian' => [
            ['strength' => 13],
        ],
        'bard' => [
            ['charisma' => 13],
        ],
        'cleric' => [
            ['wisdom' => 13],
        ],
        'druid' => [
            ['wisdom' => 13],
        ],
        'fighter' => [
            ['strength' => 13],
            ['dexterity' => 13],
        ],
        'monk' => [
            ['dexterity' => 13, 'wisdom' => 13],
        ],
        'paladin' => [
            ['strength' => 13, 'charisma' => 13],
        ],
        'ranger' => [
            ['dexterity' => 13, 'wisdom' => 13],
        ],
        'rogue' => [
            ['dexterity' => 13],
        ],
        'sorcerer' => [
            ['charisma' => 13],
        ],
        'warlock' => [
            ['charisma' => 13],
        ],
        'wizard' => [
            ['intelligence' => 13],
        ],
        'artificer' => [
            ['intelligence' => 13],
        ],
    ];

    public function __construct(
        private CharacterAbilityCalculator $abilityCalculator,
    ) {
    }

    public function canTakeLevel(
        Character $character,
        CharacterClass $characterClass,
    ): bool {
        return $this->missingRequirements(
            $character,
            $characterClass,
        ) === [];
    }

    /**
     * @return list<string>
     */
    public function missingRequirements(
        Character $character,
        CharacterClass $characterClass,
    ): array {
        // Initial class assignment and advancement in an existing class are
        // not entry into a new multiclass.
        if ($character->getTotalLevel() === 0 || $character->getLevelInClass($characterClass) > 0) {
            return [];
        }

        $classes = [$characterClass->getSlug() => $characterClass];
        foreach ($character->getClassLevels() as $level) {
            $existingClass = $level->getCharacterClass();
            $classes[$existingClass->getSlug()] = $existingClass;
        }

        $missing = [];
        foreach ($classes as $class) {
            $alternatives = $this->missingClassRequirements($character, $class);
            if ($alternatives !== []) {
                $missing[] = sprintf('%s : %s', $class->getName(), implode(' ou ', $alternatives));
            }
        }

        return $missing;
    }

    /** @return list<string> */
    private function missingClassRequirements(Character $character, CharacterClass $characterClass): array
    {
        $alternatives =
            self::REQUIREMENTS[$characterClass->getSlug()]
            ?? [];

        if ($alternatives === []) {
            return [];
        }

        foreach ($alternatives as $requirements) {
            if ($this->meetsRequirements($character, $requirements)) {
                return [];
            }
        }

        return array_map(
            fn (array $requirements): string =>
                $this->formatRequirements($requirements),
            $alternatives,
        );
    }

    /**
     * @param array<string, int> $requirements
     */
    private function meetsRequirements(
        Character $character,
        array $requirements,
    ): bool {
        foreach ($requirements as $ability => $minimum) {
            $abilityEnum = Ability::from($ability);

            if (
                $this->abilityCalculator
                    ->calculate($character, $abilityEnum)
                    ->effectiveValue < $minimum
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, int> $requirements
     */
    private function formatRequirements(array $requirements): string
    {
        $labels = [
            'strength' => 'FOR',
            'dexterity' => 'DEX',
            'constitution' => 'CON',
            'intelligence' => 'INT',
            'wisdom' => 'SAG',
            'charisma' => 'CHA',
        ];

        $parts = [];

        foreach ($requirements as $ability => $minimum) {
            $parts[] = sprintf(
                '%s %d',
                $labels[$ability] ?? $ability,
                $minimum,
            );
        }

        return implode(' et ', $parts);
    }
}

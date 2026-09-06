<?php

declare(strict_types=1);

namespace App\Enum;

enum Ability: string
{
    case Strength = 'strength';
    case Dexterity = 'dexterity';
    case Constitution = 'constitution';
    case Intelligence = 'intelligence';
    case Wisdom = 'wisdom';
    case Charisma = 'charisma';

    public function label(): string
    {
        return match ($this) {
            self::Strength =>
                'Force',
            self::Dexterity =>
                'Dextérité',
            self::Constitution =>
                'Constitution',
            self::Intelligence =>
                'Intelligence',
            self::Wisdom =>
                'Sagesse',
            self::Charisma =>
                'Charisme',
        };
    }

    public function abbreviation(): string
    {
        return match ($this) {
            self::Strength => 'FOR',
            self::Dexterity => 'DEX',
            self::Constitution => 'CON',
            self::Intelligence => 'INT',
            self::Wisdom => 'SAG',
            self::Charisma => 'CHA',
        };
    }
}

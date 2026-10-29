<?php

declare(strict_types=1);

namespace App\Enum;

enum SpellSchool: string
{
    case Abjuration = 'abjuration';
    case Conjuration = 'conjuration';
    case Divination = 'divination';
    case Enchantment = 'enchantment';
    case Evocation = 'evocation';
    case Illusion = 'illusion';
    case Necromancy = 'necromancy';
    case Transmutation = 'transmutation';

    public function label(): string
    {
        return match ($this) {
            self::Abjuration => 'Abjuration',
            self::Conjuration => 'Invocation',
            self::Divination => 'Divination',
            self::Enchantment => 'Enchantement',
            self::Evocation => 'Évocation',
            self::Illusion => 'Illusion',
            self::Necromancy => 'Nécromancie',
            self::Transmutation => 'Transmutation',
        };
    }
}

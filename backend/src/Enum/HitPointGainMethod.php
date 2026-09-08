<?php

declare(strict_types=1);

namespace App\Enum;

enum HitPointGainMethod: string
{
    case FirstLevel = 'first_level';
    case Average = 'average';
    case Rolled = 'rolled';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::FirstLevel => 'Maximum du dé de vie',
            self::Average => 'Valeur moyenne',
            self::Rolled => 'Dé lancé',
            self::Manual => 'Saisie manuelle',
        };
    }
}

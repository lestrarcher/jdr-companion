<?php

declare(strict_types=1);

namespace App\Enum;

enum FeatureActivationType: string
{
    case Passive = 'passive';
    case Action = 'action';
    case BonusAction = 'bonus_action';
    case Reaction = 'reaction';
    case FreeAction = 'free_action';
    case Special = 'special';

    public function label(): string
    {
        return match ($this) {
            self::Passive => 'Passive',
            self::Action => 'Action',
            self::BonusAction => 'Action bonus',
            self::Reaction => 'Réaction',
            self::FreeAction => 'Action libre',
            self::Special => 'Spéciale',
        };
    }
}

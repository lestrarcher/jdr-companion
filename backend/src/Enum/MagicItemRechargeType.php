<?php

declare(strict_types=1);

namespace App\Enum;

enum MagicItemRechargeType: string
{
    case None = 'none';
    case Manual = 'manual';
    case ShortRest = 'short-rest';
    case LongRest = 'long-rest';
    case Daily = 'daily';

    public function label(): string
    {
        return match ($this) {
            self::None =>
                'Aucune recharge',
            self::Manual =>
                'Recharge manuelle',
            self::ShortRest =>
                'Repos court',
            self::LongRest =>
                'Repos long',
            self::Daily =>
                'Recharge quotidienne',
        };
    }
}

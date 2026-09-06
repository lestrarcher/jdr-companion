<?php

declare(strict_types=1);

namespace App\Enum;

enum MagicItemRarity: string
{
    case Common = 'common';
    case Uncommon = 'uncommon';
    case Rare = 'rare';
    case VeryRare = 'very-rare';
    case Legendary = 'legendary';
    case Artifact = 'artifact';

    public function label(): string
    {
        return match ($this) {
            self::Common =>
                'Commun',
            self::Uncommon =>
                'Peu commun',
            self::Rare =>
                'Rare',
            self::VeryRare =>
                'Très rare',
            self::Legendary =>
                'Légendaire',
            self::Artifact =>
                'Artefact',
        };
    }
}

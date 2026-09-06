<?php

declare(strict_types=1);

namespace App\Enum;

enum AbilityEffectOperation: string
{
    /**
     * Ajoute une valeur à la caractéristique.
     *
     * Exemple : +2 en Sagesse.
     */
    case Bonus = 'bonus';

    /**
     * Impose une valeur minimale.
     *
     * Exemple : Intelligence minimale de 19.
     */
    case Minimum = 'minimum';

    /**
     * Modifie définitivement la valeur de base
     * et éventuellement son maximum.
     *
     * Exemple : Tome de compréhension.
     */
    case PermanentIncrease =
        'permanent-increase';

    public function label(): string
    {
        return match ($this) {
            self::Bonus =>
                'Bonus temporaire',
            self::Minimum =>
                'Valeur minimale',
            self::PermanentIncrease =>
                'Augmentation permanente',
        };
    }
}

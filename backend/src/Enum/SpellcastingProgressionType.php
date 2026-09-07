<?php

declare(strict_types=1);

namespace App\Enum;

enum SpellcastingProgressionType: string
{
    case None = 'none';

    /**
     * Barde, clerc, druide,
     * ensorceleur et magicien.
     */
    case Full = 'full';

    /**
     * Paladin et rôdeur :
     * moitié du niveau de classe,
     * arrondie à l’inférieur.
     */
    case Half = 'half';

    /**
     * Artificier :
     * moitié du niveau de classe,
     * arrondie au supérieur.
     */
    case Artificer = 'artificer';

    /**
     * Chevalier occulte
     * et Roublard mystificateur :
     * tiers du niveau de classe.
     */
    case Third = 'third';

    /**
     * Emplacements d’occultiste,
             * séparés des emplacements classiques.
     */
    case Pact = 'pact';

    public function label(): string
    {
        return match ($this) {
            self::None =>
                'Aucune magie',

            self::Full =>
                'Lanceur de sorts complet',

            self::Half =>
                'Demi-lanceur de sorts',

            self::Artificer =>
                'Progression d’artificier',

            self::Third =>
                'Tiers de lanceur de sorts',

            self::Pact =>
                'Magie de pacte',
        };
    }
}

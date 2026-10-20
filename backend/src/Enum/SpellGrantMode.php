<?php
declare(strict_types=1);
namespace App\Enum;

enum SpellGrantMode: string
{
    case AddToList = 'add-to-list';
    case Known = 'grant-known';
    case AlwaysPrepared = 'grant-always-prepared';
    case Cast = 'grant-cast';

    public function label(): string
    {
        return match ($this) {
            self::AddToList => 'Ajout à la liste de classe',
            self::Known => 'Automatiquement connu',
            self::AlwaysPrepared => 'Toujours préparé',
            self::Cast => 'Accès particulier au lancement',
        };
    }
}

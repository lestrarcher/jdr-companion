<?php

declare(strict_types=1);

namespace App\Enum;

enum DamageType: string
{
    case Acid = 'acid';
    case Bludgeoning = 'bludgeoning';
    case Cold = 'cold';
    case Fire = 'fire';
    case Force = 'force';
    case Lightning = 'lightning';
    case Necrotic = 'necrotic';
    case Piercing = 'piercing';
    case Poison = 'poison';
    case Psychic = 'psychic';
    case Radiant = 'radiant';
    case Slashing = 'slashing';
    case Thunder = 'thunder';
}

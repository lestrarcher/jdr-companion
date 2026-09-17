<?php

declare(strict_types=1);

namespace App\Enum;

enum CreatureSize: string
{
    case Tiny = 'tiny';
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';
    case Huge = 'huge';
    case Gargantuan = 'gargantuan';
}

<?php

declare(strict_types=1);

namespace App\Enum;

enum CharacterActionHandlerType: string
{
    case Aid = 'aid';
    case HeroesFeast = 'heroes-feast';
    case FlexibleCasting = 'flexible-casting';
}

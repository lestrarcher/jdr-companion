<?php

declare(strict_types=1);

namespace App\Enum;

enum ConditionType: string
{
    case Blinded = 'blinded';
    case Charmed = 'charmed';
    case Deafened = 'deafened';
    case Exhaustion = 'exhaustion';
    case Frightened = 'frightened';
    case Grappled = 'grappled';
    case Incapacitated = 'incapacitated';
    case Invisible = 'invisible';
    case Paralyzed = 'paralyzed';
    case Petrified = 'petrified';
    case Poisoned = 'poisoned';
    case Prone = 'prone';
    case Restrained = 'restrained';
    case Stunned = 'stunned';
    case Unconscious = 'unconscious';
}

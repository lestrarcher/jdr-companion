<?php

declare(strict_types=1);

namespace App\Enum;

enum AbilityAdjustmentOperation: string
{
    case Increase = 'increase';
    case Set = 'set';
}

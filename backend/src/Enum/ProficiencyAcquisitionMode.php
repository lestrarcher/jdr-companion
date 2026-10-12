<?php

declare(strict_types=1);

namespace App\Enum;

enum ProficiencyAcquisitionMode: string
{
    case Any = 'ANY';
    case StartingClass = 'STARTING_CLASS';
    case Multiclass = 'MULTICLASS';
}

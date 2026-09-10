<?php

declare(strict_types=1);

namespace App\Enum;

enum ProgressionAdjustmentDirection: string
{
    case GAIN = 'gain';
    case LOSS = 'loss';
}

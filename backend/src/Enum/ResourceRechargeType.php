<?php

declare(strict_types=1);

namespace App\Enum;

enum ResourceRechargeType: string
{
    case None = 'none';
    case ShortRest = 'short-rest';
    case LongRest = 'long-rest';
}

<?php

declare(strict_types=1);

namespace App\Enum;

enum ReferenceOrigin: string
{
    case Official = 'OFFICIAL';
    case Custom = 'CUSTOM';
}

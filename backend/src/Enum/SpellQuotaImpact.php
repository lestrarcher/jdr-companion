<?php
declare(strict_types=1);
namespace App\Enum;

/** Impact only; the applicable quota is selected from the mode and source policy. */
enum SpellQuotaImpact: string
{
    case Counts = 'counts-toward-quota';
    case Free = 'free';
    case Unspecified = 'unspecified';
}

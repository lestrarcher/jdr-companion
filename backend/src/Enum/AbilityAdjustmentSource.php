<?php

declare(strict_types=1);

namespace App\Enum;

enum AbilityAdjustmentSource: string
{
    case AbilityScoreImprovement = 'ability-score-improvement';
    case Narrative = 'narrative';
    case Other = 'other';
}

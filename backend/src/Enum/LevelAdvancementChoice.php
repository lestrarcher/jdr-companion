<?php

declare(strict_types=1);

namespace App\Enum;

enum LevelAdvancementChoice: string
{
    case None = 'none';
    case AbilityScoreImprovementOrFeat = 'ability-score-improvement-or-feat';
}

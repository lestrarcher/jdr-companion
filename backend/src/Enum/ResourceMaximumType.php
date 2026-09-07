<?php

declare(strict_types=1);

namespace App\Enum;

enum ResourceMaximumType: string
{
    case Fixed = 'fixed';
    case ProficiencyBonus = 'proficiency-bonus';
    case AbilityModifier = 'ability-modifier';
}

<?php

declare(strict_types=1);

namespace App\Enum;

enum ProficiencyType: string
{
    case Skill = 'SKILL';
    case SavingThrow = 'SAVING_THROW';
    case Weapon = 'WEAPON';
    case WeaponGroup = 'WEAPON_GROUP';
    case Armor = 'ARMOR';
    case Tool = 'TOOL';
}

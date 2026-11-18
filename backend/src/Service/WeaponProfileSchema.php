<?php
declare(strict_types=1);
namespace App\Service;

/** Physical dice exclude ability, proficiency and magical bonuses. Distances in metres. */
final class WeaponProfileSchema
{
    public static function validate(array $p): array
    {
        $required = ['proficiency','group','mode','dice','damageType','properties','attackBonus','damageBonus'];
        if (array_diff($required, array_keys($p)) || array_diff(array_keys($p), [...$required,'versatile','range','notes'])) self::invalid();
        if (!is_string($p['proficiency']) || !preg_match('/^[a-z][a-z0-9-]{0,79}$/D', $p['proficiency'])
            || !in_array($p['group'], ['simple-weapons','martial-weapons'], true)
            || !in_array($p['mode'], ['melee','ranged'], true)
            || !in_array($p['damageType'], ['slashing','piercing','bludgeoning'], true)) self::invalid();
        foreach (['dice','versatile'] as $key) if (isset($p[$key]) && (!is_string($p[$key]) || !preg_match('/^(?:[1-9][0-9]?d(?:4|6|8|10|12)|1)$/D', $p[$key]))) self::invalid();
        if (!is_string($p['dice'])) self::invalid();
        foreach (['attackBonus','damageBonus'] as $key) if (!is_int($p[$key]) || $p[$key] < 0 || $p[$key] > 3) self::invalid();
        if (!is_array($p['properties']) || !array_is_list($p['properties'])) self::invalid();
        foreach ($p['properties'] as $property) if (!is_string($property) || !in_array($property, ['finesse','light','heavy','two-handed','reach','thrown','ammunition','loading','special'], true)) self::invalid();
        if (count(array_unique($p['properties'])) !== count($p['properties'])) self::invalid();
        if (isset($p['range']) && (!is_array($p['range']) || !array_is_list($p['range']) || count($p['range']) !== 2
            || !is_numeric($p['range'][0]) || !is_numeric($p['range'][1]) || $p['range'][0] <= 0 || $p['range'][1] < $p['range'][0])) self::invalid();
        if (isset($p['notes']) && (!is_string($p['notes']) || mb_strlen($p['notes']) > 1000)) self::invalid();
        return $p;
    }
    private static function invalid(): never { throw new \InvalidArgumentException('Profil mécanique d’arme invalide.'); }
}

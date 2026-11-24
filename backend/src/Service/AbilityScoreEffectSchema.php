<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Ability;

/** One entry per affected ability; scoreCap can restrict, never raise, its maximum. */
final class AbilityScoreEffectSchema
{
    public static function validate(mixed $effects): array
    {
        if (!is_array($effects) || !array_is_list($effects) || count($effects) > 20) {
            throw new \InvalidArgumentException('Les effets de caractéristiques doivent former une liste (20 maximum).');
        }
        $keys = [];
        foreach ($effects as $effect) {
            if (!is_array($effect)
                || array_diff(array_keys($effect), ['key', 'label', 'ability', 'value', 'scoreCap'])
                || !is_string($effect['key'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $effect['key'])
                || isset($keys[$effect['key']])
                || !is_string($effect['label'] ?? null) || trim($effect['label']) === '' || mb_strlen($effect['label']) > 150
                || !is_string($effect['ability'] ?? null) || Ability::tryFrom($effect['ability']) === null
                || !is_int($effect['value'] ?? null) || $effect['value'] === 0 || abs($effect['value']) > 30
                || (array_key_exists('scoreCap', $effect)
                    && (!is_int($effect['scoreCap']) || $effect['scoreCap'] < 1 || $effect['scoreCap'] > 30))) {
                throw new \InvalidArgumentException('Effet de caractéristique invalide ou clé dupliquée.');
            }
            $keys[$effect['key']] = true;
        }
        return $effects;
    }
}

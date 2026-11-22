<?php

declare(strict_types=1);

namespace App\Service;

/** Permanent maximum bonuses; neither raw level gains nor current HP. */
final class HitPointEffectSchema
{
    public static function validate(mixed $effects): array
    {
        if (!is_array($effects) || !array_is_list($effects) || count($effects) > 20) {
            throw new \InvalidArgumentException('Les effets de PV doivent former une liste (20 maximum).');
        }
        $keys = [];
        foreach ($effects as $effect) {
            if (!is_array($effect)
                || array_diff(array_keys($effect), ['key', 'label', 'type', 'value', 'classSlug'])
                || !is_string($effect['key'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $effect['key'])
                || isset($keys[$effect['key']])
                || !is_string($effect['label'] ?? null) || trim($effect['label']) === '' || mb_strlen($effect['label']) > 150
                || !in_array($effect['type'] ?? null, ['fixed', 'per-total-level', 'per-class-level'], true)
                || !is_int($effect['value'] ?? null) || $effect['value'] < 1 || $effect['value'] > 1000) {
                throw new \InvalidArgumentException('Bonus de PV invalide ou clé dupliquée.');
            }
            if ($effect['type'] === 'per-class-level') {
                if (!is_string($effect['classSlug'] ?? null) || !preg_match('/^[a-z][a-z0-9-]{0,79}$/D', $effect['classSlug'])) {
                    throw new \InvalidArgumentException('Classe du bonus de PV invalide.');
                }
            } elseif (array_key_exists('classSlug', $effect)) {
                throw new \InvalidArgumentException('Seul un bonus par niveau de classe peut désigner une classe.');
            }
            $keys[$effect['key']] = true;
        }
        return $effects;
    }
}

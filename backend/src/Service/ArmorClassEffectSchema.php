<?php

declare(strict_types=1);

namespace App\Service;

/** Declarative AC effects, never inferred from catalogue text. Shared by items and explicit runtime contexts. */
final class ArmorClassEffectSchema
{
    public static function validate(mixed $effects): array
    {
        if (!is_array($effects) || !array_is_list($effects) || count($effects) > 20) {
            throw new \InvalidArgumentException('Les effets de CA doivent former une liste (20 maximum).');
        }
        $keys = [];
        foreach ($effects as $effect) {
            if (!is_array($effect) || array_diff(array_keys($effect), ['key', 'label', 'type', 'value', 'dexterity', 'dexterityCap', 'ability', 'conditions', 'stackingKey', 'classSlugs'])
                || !is_string($effect['key'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $effect['key'])
                || isset($keys[$effect['key']]) || !is_string($effect['label'] ?? null) || trim($effect['label']) === '' || mb_strlen($effect['label']) > 150
                || !in_array($effect['type'] ?? null, ['bonus', 'formula'], true)
                || !is_int($effect['value'] ?? null) || $effect['value'] < 1 || $effect['value'] > 30) {
                throw new \InvalidArgumentException('Effet de CA invalide ou clé dupliquée.');
            }
            $keys[$effect['key']] = true;
            if (array_key_exists('classSlugs', $effect)) {
                $classes = $effect['classSlugs'];
                if (!is_array($classes) || !array_is_list($classes) || $classes === [] || count($classes) > 20
                    || array_filter($classes, static fn ($slug) => !is_string($slug) || !preg_match('/^[a-z][a-z0-9-]{0,79}$/D', $slug))) {
                    throw new \InvalidArgumentException('Classes requises invalides.');
                }
            }
            $conditions = $effect['conditions'] ?? [];
            if (!is_array($conditions) || !array_is_list($conditions)
                || array_filter($conditions, static fn ($c) => !is_string($c) || !in_array($c, ['no-armor', 'no-shield', 'armor', 'held'], true))
                || count(array_unique($conditions)) !== count($conditions)
                || (in_array('armor', $conditions, true) && in_array('no-armor', $conditions, true))) {
                throw new \InvalidArgumentException('Conditions de CA invalides.');
            }
            if (isset($effect['stackingKey']) && (!is_string($effect['stackingKey']) || !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $effect['stackingKey']))) {
                throw new \InvalidArgumentException('Groupe de non-cumul invalide.');
            }
            if ($effect['type'] === 'formula') {
                if (!in_array($effect['dexterity'] ?? null, ['full', 'capped', 'none'], true)
                    || (($effect['dexterity'] === 'capped') && (!is_int($effect['dexterityCap'] ?? null) || $effect['dexterityCap'] < 0 || $effect['dexterityCap'] > 10))
                    || ($effect['dexterity'] !== 'capped' && array_key_exists('dexterityCap', $effect))
                    || (isset($effect['ability']) && !in_array($effect['ability'], ['strength', 'constitution', 'intelligence', 'wisdom', 'charisma'], true))) {
                    throw new \InvalidArgumentException('Formule de CA invalide.');
                }
            } elseif (array_intersect(array_keys($effect), ['dexterity', 'dexterityCap', 'ability'])) {
                throw new \InvalidArgumentException('Un bonus ne définit pas de formule.');
            }
        }
        return $effects;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

/** Permanent, declarative attacks. Conditional activation remains descriptive, not a combat state. */
final class FeatureAttackEffectSchema
{
    public static function validate(mixed $effects): array
    {
        if (!is_array($effects) || !array_is_list($effects) || count($effects) > 20) {
            throw new \InvalidArgumentException('Les effets d’attaque doivent être une liste de 20 éléments maximum.');
        }
        $keys = [];
        foreach ($effects as $effect) {
            if (!is_array($effect) || array_diff(array_keys($effect), ['key', 'type', 'name', 'dice', 'damageType', 'ability', 'proficient', 'attackBonus', 'damageBonus', 'conditions', 'notes', 'unarmed'])
                || !is_string($effect['key'] ?? null) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $effect['key'])
                || isset($keys[$effect['key']])
                || !in_array($effect['type'] ?? null, ['natural-attack', 'unarmed-damage', 'improvised-proficiency'], true)) {
                throw new \InvalidArgumentException('Effet d’attaque invalide ou clé dupliquée.');
            }
            $keys[$effect['key']] = true;
            if (!is_array($effect['conditions'] ?? []) || !array_is_list($effect['conditions'] ?? [])
                || array_filter($effect['conditions'] ?? [], static fn ($condition) => !is_string($condition))
                || array_diff($effect['conditions'] ?? [], ['no-armor', 'no-shield', 'free-hand'])
                || !is_array($effect['notes'] ?? []) || !array_is_list($effect['notes'] ?? [])
                || array_filter($effect['notes'] ?? [], static fn ($note) => !is_string($note) || strlen($note) > 2000)) {
                throw new \InvalidArgumentException('Conditions ou notes d’attaque invalides.');
            }
            if ($effect['type'] === 'improvised-proficiency') {
                if (array_diff(array_keys($effect), ['key', 'type', 'conditions', 'notes'])) throw new \InvalidArgumentException('Champs incompatibles avec une maîtrise.');
                continue;
            }
            if (!is_string($effect['dice'] ?? null) || !preg_match('/^(?:[1-9][0-9]?d(?:4|6|8|10|12|20)|[1-9][0-9]?)$/D', $effect['dice'])
                || !in_array($effect['damageType'] ?? null, ['bludgeoning', 'piercing', 'slashing'], true)
                || !in_array($effect['ability'] ?? 'strength', ['strength', 'dexterity', 'best-strength-dexterity'], true)
                || (isset($effect['unarmed']) && !is_bool($effect['unarmed']))
                || (isset($effect['proficient']) && !is_bool($effect['proficient']))) {
                throw new \InvalidArgumentException('Profil d’attaque invalide.');
            }
            foreach (['attackBonus', 'damageBonus'] as $bonus) {
                if (isset($effect[$bonus]) && (!is_int($effect[$bonus]) || abs($effect[$bonus]) > 20)) throw new \InvalidArgumentException('Bonus d’attaque invalide.');
            }
            if ($effect['type'] === 'natural-attack' && (!is_string($effect['name'] ?? null) || trim($effect['name']) === '' || mb_strlen($effect['name']) > 150)) {
                throw new \InvalidArgumentException('Nom d’attaque naturelle requis.');
            }
        }
        return $effects;
    }
}

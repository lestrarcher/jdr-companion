<?php

declare(strict_types=1);

namespace App\Service;

/** Reviewed import keys only. This is catalogue configuration, not runtime item-name logic. */
final class Dnd2014ArmorClassCatalogue
{
    public static function rules(): array
    {
        $rules = [];
        $ordinary = [
            'padded' => 'padded-armor', 'leather' => 'leather-armor', 'studded-leather' => 'studded-leather-armor',
            'hide' => 'hide-armor', 'chain-shirt' => 'chain-shirt', 'scale-mail' => 'scale-mail',
            'breastplate' => 'breastplate', 'half-plate' => 'half-plate-armor', 'ring-mail' => 'ring-mail',
            'chain-mail' => 'chain-mail', 'splint' => 'splint-armor', 'plate' => 'plate-armor',
        ];
        foreach ($ordinary as $key => $profile) {
            foreach ([1, 2, 3] as $bonus) {
                $rules['phb2014-magic-armor-'.$key.'-plus-'.$bonus] = self::armor($profile, $bonus, false);
            }
        }
        foreach ([1, 2, 3] as $bonus) {
            $rules['phb2014-magic-shield-plus-'.$bonus] = self::armor('shield', $bonus, false);
        }
        foreach (['uncommon' => [12, 'full'], 'rare' => [15, 'capped'], 'very-rare' => [18, 'none']] as $rarity => [$base, $dex]) {
            $rules['tcoe:barrier-tattoo-'.$rarity] = self::worn([[
                'key' => 'barrier-tattoo', 'label' => 'Tatouage barrière', 'type' => 'formula', 'value' => $base,
                'dexterity' => $dex, ...($dex === 'capped' ? ['dexterityCap' => 2] : []), 'conditions' => ['no-armor'],
            ]]);
        }
        $srd = 'srd-5.1:magic-item:';
        foreach (['bracers-of-defense' => [2, ['no-armor', 'no-shield']], 'ring-of-protection' => [1, []],
            'cloak-of-protection' => [1, []], 'ioun-stone-of-protection' => [1, []]] as $key => [$bonus, $conditions]) {
            $rules[$srd.$key] = self::worn([self::bonus($bonus, $key, $conditions)]);
        }
        $classes = ['sorcerer', 'warlock', 'wizard'];
        $rules[$srd.'staff-of-power'] = array_replace(self::worn([
            [...self::bonus(2, 'staff-of-power', ['held']), 'classSlugs' => $classes],
        ]), ['equipment_slot' => 'hand', 'hand_usage' => 'versatile']);
        $rules[$srd.'robe-of-the-archmagi'] = self::worn([[
            'key' => 'robe-of-the-archmagi', 'label' => 'Protection de la robe', 'type' => 'formula', 'value' => 15,
            'dexterity' => 'full', 'conditions' => ['no-armor'], 'classSlugs' => $classes,
        ]]);
        foreach (['demon-armor' => ['plate-armor', 1, true], 'dwarven-plate' => ['plate-armor', 2, false],
            'elven-chain' => ['chain-shirt', 1, false], 'glamoured-studded-leather-armor' => ['studded-leather-armor', 1, false]] as $key => [$profile, $bonus, $attuned]) {
            $rules[$srd.$key] = self::armor($profile, $bonus, $attuned);
        }
        foreach (['', '-black', '-blue', '-brass', '-bronze', '-copper', '-gold', '-green', '-red', '-silver', '-white'] as $suffix) {
            $rules[$srd.'dragon-scale-mail'.$suffix] = self::armor('scale-mail', 1, true);
        }
        // Bonus known, physical armor variant unknown: preserve the incomplete result until manually resolved.
        foreach ([1, 2, 3] as $bonus) {
            $rules[$srd.'armor-'.$bonus] = ['category' => 'armor', 'magical' => true, 'equippable' => true,
                'equipment_slot' => 'armor', 'hand_usage' => null, 'requires_attunement' => false,
                'armor_class_effects' => [self::bonus($bonus, 'armor-enhancement', ['armor'])]];
        }
        return $rules;
    }

    private static function worn(array $effects): array
    {
        return ['category' => 'other', 'magical' => true, 'equippable' => true, 'equipment_slot' => 'free',
            'hand_usage' => null, 'requires_attunement' => true, 'armor_class_effects' => $effects];
    }

    private static function armor(string $profile, int $bonus, bool $attuned): array
    {
        [$type, $base, $strength, $stealth] = Dnd2014ArmorProfiles::PROFILES[$profile];
        $shield = $type === 'shield';
        return ['category' => $shield ? 'shield' : 'armor', 'magical' => true, 'equippable' => true,
            'equipment_slot' => $shield ? 'hand' : 'armor', 'hand_usage' => $shield ? 'one' : null,
            'requires_attunement' => $attuned, 'armor_type' => $type, 'base_armor_class' => $base,
            'armor_strength_requirement' => $strength, 'armor_stealth_disadvantage' => $stealth,
            'armor_class_effects' => [self::bonus($bonus, $shield ? 'shield-enhancement' : 'armor-enhancement', $shield ? ['held'] : ['armor'])]];
    }

    private static function bonus(int $value, string $group, array $conditions): array
    {
        return ['key' => $group, 'label' => 'Bonus de CA', 'type' => 'bonus', 'value' => $value,
            'conditions' => $conditions, 'stackingKey' => $group];
    }

    public static function limitations(): array
    {
        $srd = 'srd-5.1:magic-item:';
        return [
            $srd.'armor' => 'Bonus et variante physique ambigus : aucune configuration.',
            $srd.'armor-1' => 'Bonus +1 configuré, variante physique à préciser.',
            $srd.'armor-2' => 'Bonus +2 configuré, variante physique à préciser.',
            $srd.'armor-3' => 'Bonus +3 configuré, variante physique à préciser.',
            $srd.'adamantine-armor' => 'Variante physique inconnue ; protection contre les critiques hors CA.',
            $srd.'mithral-armor' => 'Variante physique inconnue ; propriétés de Force/Discrétion non déduites.',
            $srd.'armor-of-resistance' => 'Variante physique inconnue ; résistance hors CA.',
            $srd.'arrow-catching-shield' => '+2 uniquement contre les attaques à distance : contexte non géré, aucun bonus permanent.',
            $srd.'defender' => 'Transfert variable du bonus après une attaque jusqu’au prochain tour : non géré.',
            $srd.'rod-of-alertness' => 'Aura activée, durée et distance : non gérées.',
            $srd.'ioun-stone' => 'Définition générique multivariantes : utiliser la pierre de protection précise.',
            $srd.'animated-shield' => 'Profil physique existant conservé ; activation libérant les mains non gérée.',
            $srd.'robe-of-the-archmagi' => 'Prérequis de classe contrôlé ; couleur/alignement à valider manuellement avant harmonisation.',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

/** Physical properties only. No name/description parsing, AC enchantments or animated states.
 * Sources: SRD 5.1 armor table; backend/data/srd-magic-items-2014.json (exact indices).
 * https://www.dndbeyond.com/sources/dnd/basic-rules-2014/equipment#ArmorandShields
 */
final class Dnd2014ArmorProfiles
{
    /** type, base AC, strength requirement, stealth disadvantage */
    public const PROFILES = [
        'padded-armor' => ['light', 11, null, true],
        'leather-armor' => ['light', 11, null, false],
        'studded-leather-armor' => ['light', 12, null, false],
        'hide-armor' => ['medium', 12, null, false],
        'chain-shirt' => ['medium', 13, null, false],
        'scale-mail' => ['medium', 14, null, true],
        'breastplate' => ['medium', 14, null, false],
        'half-plate-armor' => ['medium', 15, null, true],
        'ring-mail' => ['heavy', 14, null, true],
        'chain-mail' => ['heavy', 16, 13, true],
        'splint-armor' => ['heavy', 17, 15, true],
        'plate-armor' => ['heavy', 18, 15, true],
        'shield' => ['shield', 2, null, false],
    ];

    public static function mappings(): array
    {
        $map = [];
        foreach (array_keys(self::PROFILES) as $key) $map['srd-5.1:equipment:'.$key] = $key;
        foreach (['armor-of-invulnerability', 'armor-of-vulnerability', 'demon-armor', 'dwarven-plate', 'plate-armor-of-etherealness'] as $key) {
            $map['srd-5.1:magic-item:'.$key] = 'plate-armor';
        }
        foreach (['animated-shield', 'arrow-catching-shield', 'shield-of-missile-attraction', 'spellguard-shield'] as $key) {
            $map['srd-5.1:magic-item:'.$key] = 'shield';
        }
        $map['srd-5.1:magic-item:elven-chain'] = 'chain-shirt';
        $map['srd-5.1:magic-item:glamoured-studded-leather-armor'] = 'studded-leather-armor';
        $map['srd-5.1:magic-item:dragon-scale-mail'] = 'scale-mail';
        foreach (['black','blue','brass','bronze','copper','gold','green','red','silver','white'] as $color) {
            $map['srd-5.1:magic-item:dragon-scale-mail-'.$color] = 'scale-mail';
        }
        return $map;
    }
}

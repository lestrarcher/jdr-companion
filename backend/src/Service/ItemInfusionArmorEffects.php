<?php

declare(strict_types=1);
namespace App\Service;

use App\Entity\Character;
use Doctrine\DBAL\Connection;

/** B2: one read for all equipped supports and their creators; no stored bonus. */
final readonly class ItemInfusionArmorEffects
{
    public function __construct(private Connection $db) {}

    /** Item-scoped projections, not global attack modifiers or combat execution. */
    public function itemProperties(Character $owner, bool $theoreticalWeaponUse = false): array
    {
        if ($owner->getId() === null) return [];
        if (!$this->db->fetchOne('SELECT 1 FROM character_item_infusion i JOIN character_magic_item owned ON owned.id=i.item_id WHERE i.active AND owned.character_id=? LIMIT 1', [$owner->getId()])) return [];
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
SELECT i.id, i.item_id, creator.name AS creator_name, feature.slug, feature.name,
       owned.equipped, owned.attuned, item.requires_attunement, item.equippable, item.category, item.weapon_profile,
       (SELECT COUNT(*) FROM character_class_level l JOIN character_class c ON c.id=l.character_class_id
        WHERE l.character_id=i.creator_id AND c.slug='artificer') AS artificer_level
FROM character_item_infusion i
JOIN character_magic_item owned ON owned.id=i.item_id
JOIN magic_item item ON item.id=owned.magic_item_id
JOIN character creator ON creator.id=i.creator_id
JOIN character_feature_choice choice ON choice.key=i.choice_key AND choice.character_id=i.creator_id
JOIN feature_choice_option opt ON opt.key=choice.option_key
JOIN feature_choice_group grp ON grp.key=opt.group_key
JOIN feature_choice_grant g ON g.key=choice.grant_key AND g.group_key=grp.key
JOIN character_feature_definition feature ON feature.id=opt.feature_id
WHERE i.active AND choice.active AND opt.enabled AND grp.enabled AND g.enabled
  AND grp.key='artificer-infusions' AND owned.character_id=? AND owned.campaign_id=creator.campaign_id
  AND owned.quantity=1 AND NOT owned.legacy_stack AND NOT item.magical
ORDER BY i.id
SQL, [$owner->getId()]);
        $result = [];
        foreach ($rows as $row) {
            $level = (int)$row['artificer_level'];
            if ($level < 2) continue;
            $bonus = $level >= 10 ? 2 : 1;
            $profile = $row['weapon_profile'] === null ? null : json_decode($row['weapon_profile'], true, 512, JSON_THROW_ON_ERROR);
            $weapon = $row['category'] === 'weapon' && in_array($row['slug'], ['artificer-infusion-enhanced-weapon','artificer-infusion-returning-weapon','artificer-infusion-repeating-shot'], true);
            if ($weapon && $row['slug'] === 'artificer-infusion-returning-weapon' && !in_array('thrown', $profile['properties'] ?? [], true)) continue;
            if ($weapon && $row['slug'] === 'artificer-infusion-repeating-shot' && (($profile['mode'] ?? null) !== 'ranged' || !in_array('ammunition', $profile['properties'] ?? [], true))) continue;
            $attunement = $row['requires_attunement'] || in_array($row['slug'], ['artificer-infusion-repeating-shot', 'artificer-infusion-enhanced-arcane-focus'], true);
            $usable = ($theoreticalWeaponUse || $row['equipped']) && $row['equippable'] && (!$attunement || $row['attuned']);
            $weaponBonus = $row['slug'] === 'artificer-infusion-enhanced-weapon' ? $bonus : 1;
            $description = match ($row['slug']) {
                'artificer-infusion-enhanced-defense' => "+$bonus à la CA lorsque cette armure ou ce bouclier est équipé. Bonus calculé dans le profil.",
                'artificer-infusion-enhanced-weapon' => "+$bonus aux jets d’attaque et de dégâts avec cette arme uniquement. Totaux théoriques dans État → Armes.",
                'artificer-infusion-enhanced-arcane-focus' => "+$bonus aux attaques de sorts effectuées avec ce focaliseur tenu et harmonisé ; ignore le demi-abri pour ces attaques. Harmonisation requise. Utilisation du focaliseur à vérifier manuellement ; aucun bonus global aux sorts.",
                'artificer-infusion-returning-weapon' => '+1 aux jets d’attaque et de dégâts avec cette arme. Elle revient en main immédiatement après une attaque à distance. Retour non automatisé.',
                'artificer-infusion-repeating-shot' => '+1 aux attaques et dégâts à distance avec cette arme. Ignore Chargement ; crée une munition magique si aucune munition n’est chargée. Harmonisation requise ; gestion manuelle.',
                'artificer-infusion-resistant-armor' => 'Résistance au type choisi lors de l’imprégnation, lorsque cette armure est portée et harmonisée. Choix du type non encore raccordé : aucune résistance appliquée automatiquement.',
                'artificer-infusion-helm-of-awareness' => 'Lorsque porté et harmonisé : avantage à l’initiative ; impossible d’être surpris sauf en étant neutralisé. Conditions et avantage à gérer manuellement.',
                default => null,
            };
            if ($description === null) continue;
            $result[(int)$row['item_id']] = [
                'id'=>(int)$row['id'], 'name'=>$row['name'], 'creatorName'=>$row['creator_name'],
                'creatorLevel'=>$level, 'description'=>$description,
                'requiresAttunement'=>$attunement,
                'ignoresLoading'=>$row['slug'] === 'artificer-infusion-repeating-shot' && $usable,
                'weaponNote'=>match ($row['slug']) {
                    'artificer-infusion-returning-weapon'=>'Revient en main après une attaque à distance.',
                    'artificer-infusion-repeating-shot'=>'Si harmonisée : ignore Chargement, crée les munitions manquantes. Bonus pour les attaques à distance.',
                    default=>'',
                },
                'weaponBonuses'=>$weapon ? ['attack'=>$usable ? $weaponBonus : 0, 'damage'=>$usable ? $weaponBonus : 0,
                    'itemId'=>(int)$row['item_id'], 'requiresUse'=>true] : null,
            ];
        }
        return $result;
    }

    public function resolve(Character $wearer): array
    {
        if ($wearer->getId() === null) return [];
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
SELECT i.id, i.item_id, creator.name AS creator_name, levels.artificer_level
FROM character_item_infusion i
JOIN character_magic_item owned ON owned.id=i.item_id
JOIN magic_item item ON item.id=owned.magic_item_id
JOIN character creator ON creator.id=i.creator_id
JOIN character_feature_choice choice ON choice.key=i.choice_key AND choice.character_id=i.creator_id
JOIN feature_choice_option opt ON opt.key=choice.option_key
JOIN feature_choice_group grp ON grp.key=opt.group_key
JOIN feature_choice_grant grant_rule ON grant_rule.key=choice.grant_key AND grant_rule.group_key=grp.key
JOIN character_feature_definition feature ON feature.id=opt.feature_id
JOIN (
    SELECT l.character_id, COUNT(*) AS artificer_level
    FROM character_class_level l JOIN character_class c ON c.id=l.character_class_id
    WHERE c.slug='artificer' GROUP BY l.character_id
) levels ON levels.character_id=i.creator_id
WHERE i.active AND choice.active AND opt.enabled AND grp.enabled AND grant_rule.enabled
  AND grp.key='artificer-infusions' AND feature.slug='artificer-infusion-enhanced-defense'
  AND levels.artificer_level>=2 AND owned.character_id=? AND owned.campaign_id=creator.campaign_id
  AND owned.equipped AND owned.quantity=1 AND NOT owned.legacy_stack
  AND NOT item.magical AND item.equippable AND item.category IN ('armor','shield')
  AND (NOT item.requires_attunement OR owned.attuned)
ORDER BY i.id
SQL, [$wearer->getId()]);
        return array_map(static fn (array $row) => [
            'identity'=>'infusion:'.$row['id'],
            'source'=>'Défense améliorée — '.$row['creator_name'],
            'effects'=>[['key'=>'enhanced-defense', 'label'=>'Défense améliorée', 'type'=>'bonus',
                'value'=>(int)$row['artificer_level'] >= 10 ? 2 : 1]],
        ], $rows);
    }
}

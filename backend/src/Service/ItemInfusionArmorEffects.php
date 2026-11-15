<?php

declare(strict_types=1);
namespace App\Service;

use App\Entity\Character;
use Doctrine\DBAL\Connection;

/** B2: one read for all equipped supports and their creators; no stored bonus. */
final readonly class ItemInfusionArmorEffects
{
    public function __construct(private Connection $db) {}

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

<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/** Additive, fail-closed catalogue enrichment. Existing explicit values always win. */
final class ArmorProfileEnricher
{
    public function plan(array $rows): array
    {
        $map = Dnd2014ArmorProfiles::mappings();
        $keys = [];
        foreach ($rows as $row) {
            if ($row['origin'] === 'OFFICIAL' && $row['owner_id'] === null && $row['campaign_id'] === null && $row['import_key'] !== null) {
                $keys[$row['import_key']] = ($keys[$row['import_key']] ?? 0) + 1;
            }
        }
        $result = [];
        foreach ($rows as $row) {
            $key = $row['import_key'];
            $target = $map[$key ?? ''] ?? null;
            if (!in_array($row['category'], ['armor', 'shield'], true) && $target === null) continue;
            $entry = ['id' => $row['id'], 'name' => $row['name'], 'key' => $key, 'status' => 'skipped', 'reason' => 'Correspondance officielle précise absente.', 'values' => null];
            if ($row['origin'] !== 'OFFICIAL' || $row['owner_id'] !== null || $row['campaign_id'] !== null || $row['edition'] !== '2014') {
                $entry['reason'] = 'Catalogue personnel, campagne ou édition hors périmètre.';
            } elseif (($keys[$key ?? ''] ?? 0) > 1) {
                $entry['status'] = 'conflict';
                $entry['reason'] = 'Identifiant d’import dupliqué.';
            } elseif ($target !== null) {
                [$type, $base, $strength, $stealth] = Dnd2014ArmorProfiles::PROFILES[$target];
                $values = ['armor_type' => $type, 'base_armor_class' => $base, 'armor_strength_requirement' => $strength, 'armor_stealth_disadvantage' => $stealth];
                $valid = $row['category'] === ($type === 'shield' ? 'shield' : 'armor')
                    && $row['equipment_slot'] === ($type === 'shield' ? 'hand' : 'armor')
                    && $row['equippable'] && ($type !== 'shield' || $row['hand_usage'] === 'one');
                $change = false;
                foreach ($values as $field => $value) {
                    if ($row[$field] !== null && $row[$field] !== $value) $valid = false;
                    if ($row[$field] === null && $value !== null) $change = true;
                }
                $entry['status'] = !$valid ? 'conflict' : ($change ? 'update' : 'unchanged');
                $entry['reason'] = !$valid ? 'Classification ou profil existant incompatible : aucune réécriture.' : $target;
                $entry['values'] = $values;
            }
            $result[] = $entry;
        }
        return $result;
    }

    public function run(Connection $db, bool $apply = false): array
    {
        return $db->transactional(function (Connection $db) use ($apply): array {
            // Also exclude concurrent imports/duplicate keys while reviewing and applying the plan.
            if ($apply) $db->executeStatement('LOCK TABLE magic_item IN SHARE ROW EXCLUSIVE MODE');
            $rows = $db->fetchAllAssociative('SELECT id, name, origin, owner_id, campaign_id, edition, import_key, category, equipment_slot, hand_usage, equippable, armor_type, base_armor_class, armor_strength_requirement, armor_stealth_disadvantage FROM magic_item ORDER BY id');
            $plan = $this->plan($rows);
            $conflicts = count(array_filter($plan, static fn (array $entry): bool => $entry['status'] === 'conflict'));
            $before = $this->possessionsFingerprint($db);
            $applied = 0;
            if ($apply && $conflicts === 0) {
                foreach ($plan as $entry) {
                    if ($entry['status'] !== 'update') continue;
                    $db->update('magic_item', [...$entry['values'], 'updated_at' => new \DateTimeImmutable()], ['id' => $entry['id']], [
                        'armor_type' => 'string', 'base_armor_class' => 'integer', 'armor_strength_requirement' => 'integer',
                        'armor_stealth_disadvantage' => 'boolean', 'updated_at' => 'datetime_immutable', 'id' => 'integer',
                    ]);
                    ++$applied;
                }
            }
            $after = $this->possessionsFingerprint($db);
            if ($before !== $after) throw new \RuntimeException('Les possessions ont changé pendant l’opération : annulation.');
            return ['entries' => $plan, 'conflicts' => $conflicts, 'applied' => $applied, 'possessionsBefore' => $before, 'possessionsAfter' => $after];
        });
    }

    private function possessionsFingerprint(Connection $db): string
    {
        return $db->fetchOne("SELECT md5(COALESCE(jsonb_agg(to_jsonb(p) ORDER BY p.id)::text, '[]')) FROM character_magic_item p");
    }
}

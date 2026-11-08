<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final class ArmorClassCatalogueSynchronizer
{
    /** Local seeds contain only the 42 reviewed definitions absent from the DEV export, without remote IDs. */
    public function loadSeeds(string $path): array
    {
        $document = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $seeds = [];
        foreach ($document['items'] as $seed) {
            $key = $seed['import_key'];
            if (isset($seeds[$key]) || !isset(Dnd2014ArmorClassCatalogue::rules()[$key])
                || !str_starts_with($key, 'phb2014-magic-') && !str_starts_with($key, 'tcoe:barrier-tattoo-')) {
                throw new \InvalidArgumentException('Source dupliquée ou hors périmètre : '.$key);
            }
            $seeds[$key] = $seed;
        }
        return $seeds;
    }

    public function plan(array $rows, array $seeds): array
    {
        $byKey = [];
        foreach ($rows as $row) if ($row['import_key'] !== null) $byKey[$row['import_key']][] = $row;
        $plan = [];
        foreach (Dnd2014ArmorClassCatalogue::rules() as $key => $desired) {
            ArmorClassEffectSchema::validate($desired['armor_class_effects']);
            $matches = $byKey[$key] ?? [];
            $entry = ['key' => $key, 'id' => null, 'status' => 'unchanged', 'changes' => [], 'reason' => null];
            if (count($matches) > 1) {
                $entry['status'] = 'conflict';
                $entry['reason'] = 'Clé dupliquée : aucune sélection arbitraire.';
            } elseif ($matches === []) {
                $entry['status'] = isset($seeds[$key]) ? 'import' : 'missing';
                $entry['changes'] = isset($seeds[$key]) ? $desired : [];
                $entry['reason'] = isset($seeds[$key]) ? 'Définition exportée, ID local généré.' : 'Référence SRD absente : importer le catalogue SRD avant cette configuration.';
            } else {
                $row = $matches[0];
                $entry['id'] = $row['id'];
                if ($row['origin'] !== 'OFFICIAL' || $row['owner_id'] !== null || $row['campaign_id'] !== null || $row['edition'] !== '2014' || $row['archived_at'] !== null) {
                    $entry['status'] = 'conflict';
                    $entry['reason'] = 'Définition personnelle, archivée ou édition incompatible : préservée.';
                } else {
                    foreach ($desired as $field => $value) {
                        $current = $row[$field];
                        if ($field === 'armor_class_effects' && is_string($current)) $current = json_decode($current, true, 512, JSON_THROW_ON_ERROR);
                        if ($current === $value || (is_array($current) && $current == $value)) continue;
                        // Reviewed legacy classifications, not an overwrite of user-configured mechanics.
                        $repair = ($field === 'equippable' && $current === false && $value === true)
                            || ($key === 'srd-5.1:magic-item:staff-of-power' && $field === 'equipment_slot' && $current === 'free');
                        if ($key === 'srd-5.1:magic-item:staff-of-power' && $field === 'hand_usage' && $current === 'one') continue;
                        if ($current !== null && !$repair) {
                            $entry['status'] = 'conflict';
                            $entry['reason'] = 'Propriété existante différente : '.$field.' (aucun écrasement).';
                        }
                        $entry['changes'][$field] = ['before' => $current, 'after' => $value];
                    }
                    if ($entry['status'] !== 'conflict' && $entry['changes'] !== []) $entry['status'] = 'configure';
                }
            }
            $plan[] = $entry;
        }
        return $plan;
    }

    public function run(Connection $db, array $seeds, bool $apply = false): array
    {
        return $db->transactional(function (Connection $db) use ($seeds, $apply): array {
            if ($apply) {
                $db->executeStatement('LOCK TABLE magic_item IN SHARE ROW EXCLUSIVE MODE');
                $db->executeStatement('LOCK TABLE character_magic_item IN SHARE MODE');
            }
            $rows = $db->fetchAllAssociative('SELECT * FROM magic_item ORDER BY id');
            $plan = $this->plan($rows, $seeds);
            $conflicts = count(array_filter($plan, static fn ($entry) => in_array($entry['status'], ['conflict', 'missing'], true)));
            $before = $this->fingerprint($db);
            $imported = $configured = 0;
            if ($apply && $conflicts === 0) {
                foreach ($plan as &$entry) {
                    if ($entry['status'] === 'import') {
                        $row = $this->newRow($entry['key'], $seeds[$entry['key']], $entry['changes']);
                        $db->insert('magic_item', $row, $this->types());
                        $entry['id'] = (int) $db->fetchOne('SELECT id FROM magic_item WHERE import_key = ?', [$entry['key']]);
                        ++$imported;
                    } elseif ($entry['status'] === 'configure') {
                        $changes = array_map(static fn ($change) => $change['after'], $entry['changes']);
                        $changes['updated_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                        $db->update('magic_item', $changes, ['id' => $entry['id']], $this->types());
                        ++$configured;
                    }
                }
                unset($entry);
            }
            $after = $this->fingerprint($db);
            if ($before !== $after) throw new \RuntimeException('Possessions modifiées : annulation.');
            $ids = array_column(array_filter($plan, static fn ($p) => $p['status'] === 'configure'), 'id');
            $untouched = array_values(array_filter($rows, static fn ($r) => !in_array($r['id'], $ids, true)));
            if ($apply && $conflicts === 0) {
                $current = array_column($db->fetchAllAssociative('SELECT * FROM magic_item ORDER BY id'), null, 'id');
                foreach ($untouched as $row) if ($current[$row['id']] !== $row) throw new \RuntimeException('Définition hors plan modifiée : annulation.');
            }
            return ['catalogueCountBefore' => count($rows), 'entries' => $plan, 'conflicts' => $conflicts,
                'imported' => $imported, 'configuredExisting' => $configured, 'configuredTotal' => $configured + $imported,
                'possessionsBefore' => $before, 'possessionsAfter' => $after, 'audit' => $this->audit($rows)];
        });
    }

    private function newRow(string $key, array $seed, array $mechanics): array
    {
        $rarity = $seed['rarity'];
        if (str_starts_with($key, 'phb2014-magic-shield-plus-')) $rarity = [1 => 'uncommon', 2 => 'rare', 3 => 'very-rare'][(int) substr($key, -1)];
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        return [...$mechanics, 'name' => $seed['name'], 'description' => $seed['description'], 'rarity' => $rarity,
            'english_name' => $seed['english_name'] ?: null, 'source_reference' => $seed['source_reference'],
            'price_copper' => $seed['price_copper'] === '' ? null : (int) $seed['price_copper'],
            'weight_grams' => $seed['weight_grams'] === '' ? null : (int) $seed['weight_grams'],
            'import_key' => $key, 'origin' => 'OFFICIAL', 'owner_id' => null, 'campaign_id' => null,
            'edition' => '2014', 'quantity_mode' => 'individual', 'recharge_type' => 'none', 'created_at' => $now, 'updated_at' => $now];
    }

    private function types(): array
    {
        return ['armor_class_effects' => 'json', 'magical' => 'boolean', 'equippable' => 'boolean', 'requires_attunement' => 'boolean',
            'armor_stealth_disadvantage' => 'boolean', 'base_armor_class' => 'integer', 'armor_strength_requirement' => 'integer',
            'price_copper' => 'integer', 'weight_grams' => 'integer'];
    }

    public function fingerprint(Connection $db): string
    {
        return $db->fetchOne("SELECT md5(COALESCE(jsonb_agg(to_jsonb(p) ORDER BY p.id)::text, '[]')) FROM character_magic_item p");
    }

    /** Text is used only to report audit candidates, never to generate mechanics. */
    public function audit(array $rows): array
    {
        $rules = Dnd2014ArmorClassCatalogue::rules();
        $limitations = Dnd2014ArmorClassCatalogue::limitations();
        $audit = [];
        foreach ($rows as $row) {
            $key = $row['import_key'];
            if (!isset($rules[$key ?? '']) && !isset($limitations[$key ?? '']) && !in_array($row['category'], ['armor', 'shield'], true)
                && !preg_match('/\b(?:CA|AC)\b|classe d.armure|armor class/iu', $row['description'] ?? '')) continue;
            $audit[] = ['id' => $row['id'], 'key' => $key, 'name' => $row['name'], 'category' => $row['category'],
                'supported' => isset($rules[$key ?? '']), 'limitation' => $limitations[$key ?? ''] ?? null];
        }
        return $audit;
    }
}

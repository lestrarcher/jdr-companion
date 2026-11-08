<?php
declare(strict_types=1);
namespace App\Service\Catalogue;

use App\Service\ArmorClassEffectSchema;
use Doctrine\DBAL\Connection;

/** Additive, explicit catalogue installation. Never infers character selections. */
final class FeatureChoiceCatalogue
{
    private array $report = [];
    private int $temporaryId = -1;
    public function __construct(private readonly Connection $db, private readonly string $projectDir) {}

    public function load(): array
    {
        return json_decode(file_get_contents($this->projectDir.'/data/reference/dnd-2014-feature-choices.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function run(bool $apply = false): array
    {
        // Preview never writes, including sequences. Applying repeats the same checks under a catalogue lock.
        if ($apply && !$this->db->isTransactionActive()) return $this->db->transactional(fn () => $this->run(true));
        if ($apply) {
            $this->db->executeQuery("SELECT pg_advisory_xact_lock(hashtext('feature-choice-catalogue-2014'))");
            $this->db->executeStatement('LOCK TABLE character_class_level IN SHARE MODE');
        }
        $this->report = ['created'=>[], 'reused'=>[], 'legacyPreserved'=>0];
        $this->temporaryId = -1;
        $data = $this->load();
        $features = [];
        foreach ($data['features'] as $definition) {
            $slug = $definition['slug'];
            $row = $this->db->fetchAssociative('SELECT * FROM character_feature_definition WHERE slug=?', [$slug]);
            if ($row) {
                $this->official($row, $slug);
                if (isset($definition['armorClassEffects']) && json_decode($row['armor_class_effects'] ?? 'null', true) !== $definition['armorClassEffects']) {
                    throw new \DomainException('Effets existants à examiner : '.$slug);
                }
                $features[$slug] = (int)$row['id'];
                $this->report['reused'][] = 'feature:'.$slug;
                continue;
            }
            if ($this->db->fetchOne('SELECT 1 FROM character_feature_definition WHERE lower(name)=lower(?)', [$definition['name']])) {
                throw new \DomainException('Nom déjà présent sous une autre clé : '.$definition['name']);
            }
            $effects = isset($definition['armorClassEffects']) ? json_encode(ArmorClassEffectSchema::validate($definition['armorClassEffects']), JSON_THROW_ON_ERROR) : null;
            $resource = isset($definition['resource']) ? $this->reference('trackable_resource_definition', $definition['resource']) : null;
            $features[$slug] = $this->insertId('character_feature_definition', ['slug'=>$slug,'name'=>$definition['name'],
                'description'=>$definition['description'], 'activation_type'=>'passive','visible'=>1,'custom'=>0,'origin'=>'OFFICIAL',
                'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s'),'armor_class_effects'=>$effects,'resource_definition_id'=>$resource], $apply);
        }
        foreach ($data['groups'] as $group) {
            $this->ensure('feature_choice_group', ['key'=>$group['key']], ['name'=>$group['name'],'uniqueness'=>$group['uniqueness'],
                'replacement'=>$group['replacement'],'replacement_windows'=>json_encode($group['replacementWindows'], JSON_THROW_ON_ERROR),
                'replacement_event_key'=>$group['replacementEventKey'] ?? null, 'enabled'=>1,'owner_id'=>null], $apply);
            foreach ($group['options'] as $option) {
                $this->ensure('feature_choice_option', ['key'=>$option['key']], ['group_key'=>$group['key'],'feature_id'=>$features[$option['feature']],
                    'prerequisites'=>json_encode((object)$option['prerequisites'], JSON_THROW_ON_ERROR),'source_code'=>$option['source'],'enabled'=>1], $apply);
            }
        }
        foreach ($data['grants'] as $grant) {
            $class = $this->reference('character_class', $grant['class']);
            $subclass = $grant['subclass'] === null ? null : $this->reference('character_subclass', $grant['subclass']);
            if ($subclass !== null && (int)$this->db->fetchOne('SELECT character_class_id FROM character_subclass WHERE id=?', [$subclass]) !== $class) throw new \DomainException('Sous-classe incompatible.');
            $feature = $this->reference('character_feature_definition', $grant['feature']);
            $criteria = ['feature_definition_id'=>$feature, 'character_class_id'=>$subclass === null ? $class : null,
                'character_subclass_id'=>$subclass, 'unlock_level'=>$grant['level'], 'origin'=>'OFFICIAL'];
            $rule = $this->find('character_feature_rule', $criteria);
            $ruleId = $rule ? (int)$rule['id'] : $this->insertId('character_feature_rule', [...$criteria,'display_order'=>$grant['level']], $apply);
            $this->ensure('feature_choice_grant', ['key'=>$grant['key']], ['group_key'=>$grant['group'],'rule_id'=>$ruleId,'choice_count'=>$grant['count'],'enabled'=>1], $apply);
        }
        foreach ($data['gates'] as $gate) {
            $feature = $features[$gate['feature']];
            if ($feature < 0) continue;
            $subclass = $this->reference('character_subclass', $gate['subclass']);
            $rule = $this->find('character_feature_rule', ['feature_definition_id'=>$feature,'character_subclass_id'=>$subclass,'unlock_level'=>$gate['level'],'origin'=>'OFFICIAL']);
            if (!$rule) continue; // No legacy automatic attribution to gate on a clean catalogue.
            $already = $this->find('feature_choice_rule_gate', ['rule_id'=>$rule['id']]);
            if (!$already) {
                // Preserve only rights that already exist now, once. Never snapshot future level-ups.
                $ids = $this->db->fetchFirstColumn('SELECT character_id FROM character_class_level WHERE character_class_id=(SELECT character_class_id FROM character_subclass WHERE id=?) GROUP BY character_id HAVING count(*)>=? AND bool_or(subclass_id=?)', [$subclass,$gate['level'],$subclass]);
                $this->report['legacyPreserved'] += count($ids);
                if ($apply) foreach ($ids as $id) $this->db->executeStatement('INSERT INTO feature_choice_legacy_rule(character_id,rule_id) VALUES (?,?) ON CONFLICT DO NOTHING', [$id,$rule['id']]);
            }
            $this->ensure('feature_choice_rule_gate', ['rule_id'=>$rule['id']], ['group_key'=>$gate['group']], $apply);
        }
        return $this->report;
    }

    private function reference(string $table, string $slug): int
    {
        $row = $this->db->fetchAssociative('SELECT * FROM '.$table.' WHERE slug=?', [$slug]);
        if (!$row) throw new \DomainException('Référence requise absente : '.$table.'/'.$slug.' ; importer le catalogue de classes 2014 avant les choix.');
        $this->official($row, $slug);
        return (int)$row['id'];
    }
    private function official(array $row, string $key): void
    {
        if ($row['origin'] !== 'OFFICIAL' || $row['owner_id'] !== null || ($row['custom'] ?? false)) throw new \DomainException('Référence personnelle à préserver : '.$key);
    }
    private function find(string $table, array $criteria): array|false
    {
        $clauses = $values = [];
        foreach ($criteria as $key=>$value) {
            $clauses[] = $key.($value === null ? ' IS NULL' : '=?');
            if ($value !== null) $values[] = $value;
        }
        return $this->db->fetchAssociative('SELECT * FROM '.$table.' WHERE '.implode(' AND ', $clauses), $values);
    }
    private function insertId(string $table, array $fields, bool $apply): int
    {
        $this->report['created'][] = $table.':'.($fields['slug'] ?? json_encode($fields));
        if (!$apply) return $this->temporaryId--;
        $this->db->insert($table, $fields);
        return (int)$this->db->lastInsertId();
    }
    private function ensure(string $table, array $key, array $fields, bool $apply): void
    {
        $existing = $this->find($table, $key);
        $label = $table.':'.implode(':', $key);
        if ($existing) {
            foreach ($fields as $field=>$value) {
                $actual = $existing[$field];
                $equal = in_array($field, ['prerequisites','replacement_windows'], true)
                    ? json_decode($actual, true) == json_decode($value, true)
                    : ($value === null ? $actual === null : (string)$actual === (string)$value);
                if (!$equal) throw new \DomainException('Conflit de catalogue : '.$label.' / '.$field);
            }
            $this->report['reused'][] = $label;
        } else {
            $this->report['created'][] = $label;
            if ($apply) $this->db->insert($table, [...$key,...$fields]);
        }
    }
}

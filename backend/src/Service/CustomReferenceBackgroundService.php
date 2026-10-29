<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException, NotFoundHttpException};

final class CustomReferenceBackgroundService
{
    private const TEXT = ['name' => 'name', 'slug' => 'slug', 'description' => 'description', 'equipment' => 'equipment', 'featureName' => 'feature_name', 'featureDescription' => 'feature_description'];
    private const LISTS = ['personalityTraits' => 'personality_traits', 'ideals' => 'ideals', 'bonds' => 'bonds', 'flaws' => 'flaws'];
    private const RULES = [
        'proficiencyRules' => ['proficiency_grant_rule', 'proficiency_grant_rule_option', 'proficiency_definition'],
        'languageRules' => ['language_grant_rule', 'language_grant_rule_language_definition', 'language_definition'],
    ];

    public function __construct(private readonly Connection $db) {}

    private function requireAdmin(User $owner): void
    {
        if (!in_array(SpellCatalogue::ADMIN_ROLE,$owner->getRoles(),true)) throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Administration officielle requise.');
    }

    public function list(User $owner, bool $official=false): array
    {
        if ($official) {
            $this->requireAdmin($owner);
            return array_map(fn($row)=>$this->serialize($row,$owner,true),$this->db->fetchAllAssociative("SELECT * FROM character_background WHERE origin='OFFICIAL' AND owner_id IS NULL ORDER BY name,id"));
        }
        return array_map(fn ($row) => $this->serialize($row, $owner), $this->db->fetchAllAssociative(
            "SELECT * FROM character_background WHERE (origin = 'OFFICIAL' AND owner_id IS NULL) OR (origin = 'CUSTOM' AND owner_id = ?) ORDER BY name, id", [$owner->getId()],
        ));
    }

    public function options(User $owner, bool $official=false): array
    {
        if ($official) $this->requireAdmin($owner);
        $result = [];
        foreach (['proficiencies' => 'proficiency_definition', 'languages' => 'language_definition'] as $key => $table) {
            $rows = $this->db->fetchAllAssociative("SELECT * FROM $table WHERE (origin = 'OFFICIAL' AND owner_id IS NULL)".($official?'':" OR (origin = 'CUSTOM' AND owner_id = ?)").' ORDER BY name,id', $official?[]:[$owner->getId()]);
            $result[$key] = array_values(array_map(static fn ($row) => [
                'id' => (int) $row['id'], 'name' => $row['name'], 'slug' => $row['slug'],
                'type' => $row['type'] ?? null, 'origin' => $row['origin'],
            ], array_filter($rows, static fn ($row) => $key === 'languages' || in_array($row['type'], ['SKILL', 'TOOL'], true))));
        }
        return $result;
    }

    public function get(int $id, User $owner, bool $official=false): array
    {
        return $this->serialize($this->row($id, $owner,false,$official), $owner,$official);
    }

    private function row(int $id, User $owner, bool $write = false, bool $official=false): array
    {
        if ($official) {
            $this->requireAdmin($owner);
            return $this->db->fetchAssociative("SELECT * FROM character_background WHERE id=? AND origin='OFFICIAL' AND owner_id IS NULL",[$id]) ?: throw new NotFoundHttpException('Historique officiel introuvable.');
        }
        $condition = $write ? "origin = 'CUSTOM' AND owner_id = :owner" : "((origin = 'OFFICIAL' AND owner_id IS NULL) OR (origin = 'CUSTOM' AND owner_id = :owner))";
        $row = $this->db->fetchAssociative("SELECT * FROM character_background WHERE id = :id AND $condition", ['id' => $id, 'owner' => $owner->getId()]);
        if (!$row) throw new NotFoundHttpException('Historique introuvable.');
        return $row;
    }

    public function create(array $payload, User $owner,bool $official=false): array { return $this->save(null, $payload, $owner,$official); }
    public function patch(int $id, array $payload, User $owner,bool $official=false): array { return $this->save($id, $payload, $owner,$official); }

    private function save(?int $id, array $payload, User $owner,bool $official=false): array
    {
        if ($official) $this->requireAdmin($owner);
        try {
            return $this->db->transactional(function () use ($id, $payload, $owner,$official): array {
                if ($id !== null) $this->row($id, $owner, true,$official);
                $this->lock();
                $current = $id === null ? array_fill_keys(array_keys(self::TEXT), null) + array_fill_keys([...array_keys(self::LISTS), ...array_keys(self::RULES)], [])
                    : $this->get($id, $owner,$official);
                if (array_diff(array_keys($payload), [...array_keys(self::TEXT), ...array_keys(self::LISTS), ...array_keys(self::RULES)])) throw new BadRequestHttpException('Champ inconnu ou interdit.');
                $data = array_replace($current, $payload);
                $columns = [];
                foreach (self::TEXT as $field => $column) {
                    $value = $data[$field];
                    if ($value !== null && (!is_string($value) || str_contains($value, "\0") || mb_strlen($value) > 20000)) throw new BadRequestHttpException('Texte invalide : '.$field);
                    $value = $value === null ? null : trim($value);
                    $data[$field] = $columns[$column] = $value === '' ? null : $value;
                }
                if (!$data['name'] || mb_strlen($data['name']) > 120) throw new BadRequestHttpException('Nom requis, 120 caractères maximum.');
                if (!$data['slug'] || strlen($data['slug']) > 80 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $data['slug'])) throw new BadRequestHttpException('Slug invalide.');
                if ($data['featureName'] !== null && mb_strlen($data['featureName']) > 120) throw new BadRequestHttpException('Nom d’aptitude trop long.');
                foreach (self::LISTS as $field => $column) {
                    if (!is_array($data[$field]) || !array_is_list($data[$field]) || count($data[$field]) > 100) throw new BadRequestHttpException('Liste narrative invalide : '.$field);
                    foreach ($data[$field] as &$text) {
                        if (!is_string($text) || trim($text) === '' || str_contains($text, "\0") || mb_strlen($text) > 5000) throw new BadRequestHttpException('Proposition narrative invalide.');
                        $text = trim($text);
                    }
                    unset($text);
                    $columns[$column] = json_encode($data[$field], JSON_THROW_ON_ERROR);
                }
                $frozen = $id !== null && $current['mechanicsLocked'];
                if ($frozen && $data['slug'] !== $current['slug']) throw new ConflictHttpException('Cet historique est utilisé : son slug est verrouillé.');
                foreach (self::RULES as $field => $tables) {
                    if (!array_key_exists($field, $payload)) continue;
                    $data[$field] = $this->validateRules($data[$field], $current[$field], $field, $owner,$official);
                    if ($frozen && $this->mechanics($data[$field]) !== $this->mechanics($current[$field])) throw new ConflictHttpException('Cet historique est utilisé : ses règles mécaniques sont verrouillées.');
                }
                if ($id === null) {
                    $columns += ['origin' => $official?'OFFICIAL':'CUSTOM', 'owner_id' => $official?null:$owner->getId()];
                    $names = implode(', ', array_keys($columns));
                    $marks = implode(', ', array_fill(0, count($columns), '?'));
                    $id = (int) $this->db->fetchOne("INSERT INTO character_background ($names) VALUES ($marks) RETURNING id", array_values($columns));
                } else {
                    $this->db->update('character_background', $columns, ['id' => $id]);
                }
                foreach (self::RULES as $field => $tables) {
                    if (array_key_exists($field, $payload)) $this->saveRules($id, $owner, $data[$field], $current[$field], $tables,$official);
                }
                return $this->get($id, $owner,$official);
            });
        } catch (UniqueConstraintViolationException $error) {
            throw new ConflictHttpException('Ce slug est déjà utilisé. Choisissez un autre slug.', $error);
        }
    }

    private function mechanics(array $rules): array
    {
        $result = array_map(static function ($rule) {
            $ids = $rule['optionIds']; sort($ids);
            return [$rule['id'], $rule['choiceCount'], $ids];
        }, $rules);
        usort($result, static fn ($a, $b) => $a[0] <=> $b[0]);
        return $result;
    }

    private function validateRules(mixed $rules, array $current, string $field, User $owner,bool $official=false): array
    {
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > 100) throw new BadRequestHttpException('Liste de règles invalide.');
        $available = array_column($this->options($owner,$official)[$field === 'languageRules' ? 'languages' : 'proficiencies'], null, 'id');
        $existing = array_column($current, null, 'id');
        $seen = $fixed = $slots = [];
        foreach ($rules as &$rule) {
            if ($rule instanceof \stdClass) $rule = (array) $rule;
            if (!is_array($rule) || array_diff(array_keys($rule), ['id', 'label', 'choiceCount', 'optionIds'])) throw new BadRequestHttpException('Règle invalide.');
            $rule += ['id' => null];
            if ($rule['id'] !== null && (!is_int($rule['id']) || !isset($existing[$rule['id']]) || isset($seen[$rule['id']]))) throw new BadRequestHttpException('Identifiant de règle invalide.');
            if ($rule['id'] !== null) $seen[$rule['id']] = true;
            if (!is_string($rule['label'] ?? null) || trim($rule['label']) === '' || mb_strlen($rule['label']) > 120 || str_contains($rule['label'], "\0")) throw new BadRequestHttpException('Libellé de règle invalide.');
            $rule['label'] = trim($rule['label']);
            $options = $rule['optionIds'] ?? null;
            $count = $rule['choiceCount'] ?? null;
            if (!is_array($options) || !array_is_list($options) || !$options || !is_int($count) || $count < 0 || $count > count($options)) throw new BadRequestHttpException('Nombre de choix impossible.');
            $unique = []; $types = [];
            foreach ($options as $option) {
                if (!is_int($option) || !isset($available[$option]) || isset($unique[$option])) throw new BadRequestHttpException('Option inexistante, inaccessible ou en double.');
                $unique[$option] = true;
                $types[$available[$option]['type'] ?? 'LANGUAGE'] = true;
                if ($count === 0) {
                    if (isset($fixed[$option])) throw new BadRequestHttpException('Maîtrise ou langue fixe accordée plusieurs fois.');
                    $fixed[$option] = true;
                }
            }
            if (count($types) > 1) throw new BadRequestHttpException('Séparez les compétences et les outils en groupes distincts.');
            sort($rule['optionIds']);
            for ($i = 0; $i < $count; ++$i) $slots[] = $rule['optionIds'];
        }
        unset($rule);
        // Find a distinct assignment across overlapping choice groups, excluding fixed grants.
        $assigned = [];
        $match = function (int $slot, array &$visited) use (&$match, &$assigned, $slots, $fixed): bool {
            foreach ($slots[$slot] as $option) {
                if (isset($fixed[$option]) || isset($visited[$option])) continue;
                $visited[$option] = true;
                if (!isset($assigned[$option]) || $match($assigned[$option], $visited)) { $assigned[$option] = $slot; return true; }
            }
            return false;
        };
        foreach ($slots as $slot => $_) {
            $visited = [];
            if (!$match($slot, $visited)) throw new BadRequestHttpException('Les groupes ne permettent pas de choisir des options distinctes.');
        }
        return $rules;
    }

    private function saveRules(int $backgroundId, User $owner, array $rules, array $current, array $tables,bool $official=false): void
    {
        [$table, $join, $definition] = $tables;
        $retained = array_column($rules, 'id');
        foreach ($current as $old) if (!in_array($old['id'], $retained, true)) $this->db->delete($table, ['id' => $old['id']]);
        foreach ($rules as $rule) {
            $id = $rule['id'];
            if ($id === null) {
                $id = (int) $this->db->fetchOne("INSERT INTO $table (import_key, label, unlock_level, choice_count, origin, owner_id, character_background_id) VALUES (?, ?, 1, ?, ?, ?, ?) RETURNING id",
                    [($official?'official':'custom').'-background-'.bin2hex(random_bytes(16)), $rule['label'], $rule['choiceCount'], $official?'OFFICIAL':'CUSTOM', $official?null:$owner->getId(), $backgroundId]);
            } else {
                $this->db->update($table, ['label' => $rule['label'], 'choice_count' => $rule['choiceCount']], ['id' => $id]);
            }
            $oldOptions = array_map('intval', $this->db->fetchFirstColumn("SELECT {$definition}_id FROM $join WHERE {$table}_id = ?", [$id]));
            foreach (array_diff($oldOptions, $rule['optionIds']) as $option) $this->db->delete($join, [$table.'_id' => $id, $definition.'_id' => $option]);
            foreach (array_diff($rule['optionIds'], $oldOptions) as $option) $this->db->insert($join, [$table.'_id' => $id, $definition.'_id' => $option]);
        }
    }

    public function delete(int $id, User $owner,bool $official=false): void
    {
        if ($official) $this->requireAdmin($owner);
        $this->db->transactional(function () use ($id, $owner,$official): void {
            $this->row($id, $owner, true,$official);
            $this->lock();
            $record = $this->get($id, $owner,$official);
            if ($record['mechanicsLocked']) throw new ConflictHttpException('Cet historique est utilisé ou possède des dépendances protégées.');
            $this->db->delete('character_background', ['id' => $id]);
        });
    }

    private function lock(): void
    {
        $this->db->executeStatement('LOCK TABLE character_background, proficiency_definition, language_definition, proficiency_grant_rule, proficiency_grant_rule_option, language_grant_rule, language_grant_rule_language_definition, expertise_grant_rule, character, character_proficiency_choice, character_proficiency_substitution, character_language_choice, character_expertise_choice IN SHARE ROW EXCLUSIVE MODE');
    }

    private function used(int $id): bool
    {
        return (bool) $this->db->fetchOne('SELECT 1 FROM character WHERE background_id = ? LIMIT 1', [$id])
            || (bool) $this->db->fetchOne('SELECT 1 FROM proficiency_grant_rule r WHERE r.character_background_id = ? AND (EXISTS (SELECT 1 FROM character_proficiency_choice c WHERE c.grant_rule_id = r.id) OR EXISTS (SELECT 1 FROM character_proficiency_substitution s WHERE s.grant_rule_id = r.id)) LIMIT 1', [$id])
            || (bool) $this->db->fetchOne('SELECT 1 FROM language_grant_rule r JOIN character_language_choice c ON c.source_key = r.import_key WHERE r.character_background_id = ? LIMIT 1', [$id])
            // Expertise is not editable here: never delete or rewrite this external dependency.
            || (bool) $this->db->fetchOne('SELECT 1 FROM expertise_grant_rule WHERE character_background_id = ? LIMIT 1', [$id]);
    }

    private function serialize(array $row, User $owner,bool $official=false): array
    {
        $id = (int) $row['id'];
        $data = ['id' => $id, 'origin' => $row['origin'], 'mechanicsLocked' => (!$official && $row['origin'] === 'OFFICIAL') || $this->used($id)];
        foreach (self::TEXT as $field => $column) $data[$field] = $row[$column];
        foreach (self::LISTS as $field => $column) $data[$field] = json_decode($row[$column], true, 512, JSON_THROW_ON_ERROR);
        foreach (self::RULES as $field => [$table, $join, $definition]) {
            $data[$field] = [];
            foreach ($this->db->fetchAllAssociative("SELECT * FROM $table WHERE character_background_id = ? ORDER BY id", [$id]) as $rule) {
                // An inconsistent foreign rule must never leak IDs or be writable via the parent.
                if ($rule['origin'] !== $row['origin'] || $rule['owner_id'] !== $row['owner_id']) { $data['mechanicsLocked'] = true; continue; }
                $options = array_map('intval', $this->db->fetchFirstColumn("SELECT d.id FROM $definition d JOIN $join j ON j.{$definition}_id = d.id WHERE j.{$table}_id = ? AND ((d.origin = 'OFFICIAL' AND d.owner_id IS NULL) OR (d.origin = 'CUSTOM' AND d.owner_id = ?)) ORDER BY d.id", [$rule['id'], $row['owner_id']]));
                $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM $join WHERE {$table}_id = ?", [$rule['id']]);
                if (count($options) !== $total) { $data['mechanicsLocked'] = true; continue; }
                if ((int) $rule['unlock_level'] !== 1 || ($rule['acquisition_mode'] ?? 'ANY') !== 'ANY') $data['mechanicsLocked'] = true;
                $data[$field][] = ['id' => (int) $rule['id'], 'label' => $rule['label'], 'choiceCount' => (int) $rule['choice_count'], 'optionIds' => $options];
            }
        }
        return $data;
    }
}

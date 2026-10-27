<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{SpellDefinition, User};
use App\Enum\SpellSchool;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\{ForeignKeyConstraintViolationException, UniqueConstraintViolationException};
use Symfony\Component\HttpKernel\Exception\{AccessDeniedHttpException, BadRequestHttpException, ConflictHttpException, NotFoundHttpException};

/** Shared by the JSON catalogue and the Symfony administration. */
final readonly class SpellCatalogue
{
    public const ADMIN_ROLE = 'ROLE_REFERENCE_ADMIN';
    public const TEXT = ['name' => 'name', 'originalName' => 'original_name', 'castingTime' => 'casting_time', 'range' => 'range', 'duration' => 'duration', 'description' => 'description', 'higherLevels' => 'higher_levels', 'materialDescription' => 'material_description', 'rulesVariant' => 'rules_variant', 'sourceCode' => 'source_code', 'sourceReference' => 'source_reference', 'sourceUrl' => 'source_url'];
    public const FLAGS = ['verbal' => 'verbal', 'somatic' => 'somatic', 'material' => 'material', 'materialConsumed' => 'material_consumed', 'concentration' => 'concentration', 'ritual' => 'ritual'];

    public function __construct(private Connection $db) {}

    public function canManageOfficial(User $user): bool { return in_array(self::ADMIN_ROLE, $user->getRoles(), true); }

    public function context(array $input): array
    {
        if (array_diff(array_keys($input), ['q', 'level', 'school', 'origin', 'source', 'classId', 'page', 'pageSize'])) throw new BadRequestHttpException('Filtre inconnu.');
        $context = [];
        foreach (['q' => 200, 'source' => 40, 'school' => 20, 'origin' => 8] as $key => $length) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || mb_strlen($value) > $length || str_contains($value, "\0")) throw new BadRequestHttpException('Filtre invalide : '.$key);
            if (trim($value) !== '') $context[$key] = trim($value);
        }
        if (isset($context['school']) && !SpellSchool::tryFrom($context['school'])) throw new BadRequestHttpException('École invalide.');
        if (isset($context['origin']) && !in_array($context['origin'], ['OFFICIAL', 'CUSTOM'], true)) throw new BadRequestHttpException('Origine invalide.');
        foreach (['level' => [0, 9], 'classId' => [1, 2147483647], 'page' => [1, 1000000], 'pageSize' => [1, 100]] as $key => [$min, $max]) {
            $value = $input[$key] ?? (in_array($key, ['level', 'classId'], true) ? '' : ($key === 'page' ? 1 : 25));
            if (in_array($key, ['level', 'classId'], true) && $value === '') continue;
            if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) === false) throw new BadRequestHttpException('Filtre invalide : '.$key);
            $context[$key] = (int) $value;
        }
        return $context;
    }

    public function search(array $input, User $user, bool $official = false): array
    {
        if ($official) $this->requireAdmin($user);
        if ($official) $input['origin'] = 'OFFICIAL';
        $context = $this->context($input);
        $where = "((origin = 'OFFICIAL' AND owner_id IS NULL) OR (origin = 'CUSTOM' AND owner_id = :owner))";
        $params = ['owner' => $user->getId()];
        // A corrupted dependency must not disclose another owner's class or spell.
        $where .= " AND NOT EXISTS (SELECT 1 FROM spell_class_list_entry e JOIN character_class c ON c.id = e.character_class_id WHERE e.spell_id = spell_definition.id AND NOT ((c.origin = 'OFFICIAL' AND c.owner_id IS NULL) OR (spell_definition.origin = 'CUSTOM' AND c.origin = 'CUSTOM' AND c.owner_id = spell_definition.owner_id)))";
        if (isset($context['classId'])) {
            $where .= ' AND EXISTS (SELECT 1 FROM spell_class_list_entry e WHERE e.spell_id = spell_definition.id AND e.character_class_id = :classId)';
            $params['classId'] = $context['classId'];
        }
        foreach (['level' => 'level', 'school' => 'school', 'origin' => 'origin', 'source' => 'source_code'] as $key => $column) {
            if (isset($context[$key])) { $where .= " AND $column = :$key"; $params[$key] = $context[$key]; }
        }
        if (isset($context['q'])) {
            $where .= " AND (LOWER(name) LIKE :q ESCAPE '!' OR LOWER(original_name) LIKE :q ESCAPE '!' OR LOWER(slug) LIKE :q ESCAPE '!')";
            $params['q'] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($context['q'])).'%';
        }
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM spell_definition WHERE '.$where, $params);
        $limit = $context['pageSize']; $offset = ($context['page'] - 1) * $limit;
        $rows = $this->db->fetchAllAssociative("SELECT * FROM spell_definition WHERE $where ORDER BY name, id LIMIT $limit OFFSET $offset", $params);
        $classes = $this->classesForSpells(array_column($rows, 'id'));
        return ['spells' => array_map(fn ($row) => $this->serialize($row, $user, $official, $classes[$row['id']] ?? []), $rows), 'total' => $total, 'page' => $context['page'], 'pageSize' => $limit];
    }

    private function row(int $id, User $user, bool $write = false): array
    {
        $row = $this->db->fetchAssociative("SELECT * FROM spell_definition WHERE id = ? AND ((origin = 'OFFICIAL' AND owner_id IS NULL) OR (origin = 'CUSTOM' AND owner_id = ?))".($write ? ' FOR UPDATE' : ''), [$id, $user->getId()]);
        if (!$row) throw new NotFoundHttpException('Sort introuvable.');
        if ($write && $row['origin'] === 'OFFICIAL' && !$this->canManageOfficial($user)) throw new AccessDeniedHttpException('Administration du référentiel officiel requise.');
        return $row;
    }

    public function get(int $id, User $user, bool $official = false): array
    {
        if ($official) $this->requireAdmin($user);
        $row = $this->row($id, $user);
        if ($official && $row['origin'] !== 'OFFICIAL') throw new NotFoundHttpException('Sort officiel introuvable.');
        return $this->serialize($row, $user, $official);
    }

    private function requireAdmin(User $user): void
    {
        if (!$this->canManageOfficial($user)) throw new AccessDeniedHttpException('Administration du référentiel officiel requise.');
    }

    public function classOptions(User $user, bool $official = false): array
    {
        if ($official) $this->requireAdmin($user);
        $rows = $this->db->fetchAllAssociative("SELECT id, name, origin FROM character_class WHERE (origin = 'OFFICIAL' AND owner_id IS NULL)".($official ? '' : " OR (origin = 'CUSTOM' AND owner_id = :owner)").' ORDER BY name, id', $official ? [] : ['owner' => $user->getId()]);
        return array_map(static fn ($row) => ['id' => (int) $row['id'], 'name' => $row['name'], 'origin' => $row['origin']], $rows);
    }

    /** Per-read batching only: no cache can survive a catalogue edit or an owner change. */
    private function classesForSpells(array $ids): array
    {
        if ($ids === []) return [];
        $classes = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT e.spell_id, c.id, c.name, c.origin, c.owner_id FROM spell_class_list_entry e JOIN character_class c ON c.id = e.character_class_id WHERE e.spell_id IN (?) ORDER BY c.name, c.id',
            [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        ) as $class) $classes[$class['spell_id']][] = $class;
        return $classes;
    }

    private function serialize(array $row, User $user, bool $official = false, ?array $classes = null): array
    {
        $data = ['id' => (int) $row['id'], 'slug' => $row['slug'], 'level' => (int) $row['level'], 'school' => $row['school'], 'origin' => $row['origin'], 'edition' => $row['edition'], 'importKey' => $row['import_key'], 'materialCostCp' => $row['material_cost_cp'] === null ? null : (int) $row['material_cost_cp']];
        foreach (self::TEXT as $field => $column) $data[$field] = $row[$column];
        foreach (self::FLAGS as $field => $column) $data[$field] = filter_var($row[$column], FILTER_VALIDATE_BOOL);
        $classes ??= $this->classesForSpells([(int) $row['id']])[$row['id']] ?? [];
        foreach ($classes as $class) if (!(($class['origin'] === 'OFFICIAL' && $class['owner_id'] === null) || ($row['origin'] === 'CUSTOM' && $class['origin'] === 'CUSTOM' && $class['owner_id'] === $row['owner_id']))) throw new NotFoundHttpException('Sort indisponible.');
        $data['classes'] = array_map(static fn ($class) => ['id' => (int) $class['id'], 'name' => $class['name'], 'origin' => $class['origin']], $classes);
        $data['classIds'] = array_column($data['classes'], 'id');
        $data['editable'] = $official ? $row['origin'] === 'OFFICIAL' && $this->canManageOfficial($user) : $row['origin'] === 'CUSTOM';
        $data['deletable'] = $data['editable'] && $classes === [];
        return $data;
    }

    /** Read-only list memberships, with the same defensive visibility as the catalogue. */
    public function entriesForClasses(array $classIds, User $owner): array
    {
        if ($classIds === []) return [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, spell_id, character_class_id FROM spell_class_list_entry WHERE character_class_id IN (?) ORDER BY id',
            [$classIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        $result = []; $spells = [];
        $ids = array_values(array_unique(array_column($rows, 'spell_id')));
        if ($ids === []) return [];
        $spellRows = $this->db->fetchAllAssociative(
            "SELECT * FROM spell_definition WHERE id IN (?) AND ((origin = 'OFFICIAL' AND owner_id IS NULL) OR (origin = 'CUSTOM' AND owner_id = ?))",
            [$ids, $owner->getId()], [\Doctrine\DBAL\ArrayParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER],
        );
        $classes = $this->classesForSpells(array_column($spellRows, 'id'));
        foreach ($spellRows as $spellRow) {
            try { $spells[$spellRow['id']] = $this->serialize($spellRow, $owner, false, $classes[$spellRow['id']] ?? []); }
            catch (NotFoundHttpException) { /* Keep malformed dependencies hidden, as in get(). */ }
        }
        foreach ($rows as $row) {
            $spellId = (int) $row['spell_id'];
            if (isset($spells[$spellId])) $result[] = ['id' => (int) $row['id'], 'classId' => (int) $row['character_class_id'], 'spell' => $spells[$spellId]];
        }
        return $result;
    }

    public function create(array $payload, User $user, bool $official = false): array
    {
        if ($official && !$this->canManageOfficial($user)) throw new AccessDeniedHttpException('Administration du référentiel officiel requise.');
        return $this->save(null, $payload, $user, $official);
    }

    public function patch(int $id, array $payload, User $user, bool $official = false): array { return $this->save($id, $payload, $user, $official); }

    private function save(?int $id, array $payload, User $user, bool $official): array
    {
        try {
            return $this->db->transactional(function () use ($id, $payload, $user, $official): array {
                // Acquire this before the row lock: acquisitions hold a SHARE table
                // lock while validating references and inserting their foreign keys.
                $this->db->executeStatement('LOCK TABLE spell_definition IN ROW EXCLUSIVE MODE');
                $row = $id === null ? null : $this->row($id, $user, true);
                if ($official) $this->requireAdmin($user);
                if ($row !== null && ($row['origin'] === 'OFFICIAL') !== $official) throw new AccessDeniedHttpException('Interface incompatible avec l’origine du sort.');
                $allowed = [...array_keys(self::TEXT), ...array_keys(self::FLAGS), 'level', 'school', 'materialCostCp', 'classIds', ...($id === null ? ['slug'] : [])];
                if (array_diff(array_keys($payload), $allowed)) throw new BadRequestHttpException('Champ inconnu ou protégé.');
                $data = array_replace($row === null ? array_fill_keys(array_keys(self::TEXT), null) + array_fill_keys(array_keys(self::FLAGS), false) + ['materialCostCp' => null] : $this->serialize($row, $user), $payload);
                foreach (self::TEXT as $field => $_) if ($data[$field] !== null && !is_string($data[$field])) throw new BadRequestHttpException('Texte invalide : '.$field);
                foreach (self::FLAGS as $field => $_) if (!is_bool($data[$field])) throw new BadRequestHttpException('Booléen invalide : '.$field);
                foreach (['slug', 'name', 'school', 'castingTime', 'range', 'duration', 'description'] as $field) if (!is_string($data[$field] ?? null)) throw new BadRequestHttpException('Champ requis : '.$field);
                if (!is_int($data['level'] ?? null) || ($data['materialCostCp'] !== null && !is_int($data['materialCostCp']))) throw new BadRequestHttpException('Niveau ou coût invalide.');
                $school = SpellSchool::tryFrom($data['school']) ?? throw new BadRequestHttpException('École invalide.');
                $official = $row === null ? $official : $row['origin'] === 'OFFICIAL';
                $classIds = $payload['classIds'] ?? ($id === null ? [] : $data['classIds']);
                if (array_key_exists('classIds', $payload) && $payload['classIds'] === null) throw new BadRequestHttpException('Liste de classes invalide.');
                if (!is_array($classIds) || !array_is_list($classIds) || count($classIds) > 100) throw new BadRequestHttpException('Liste de classes invalide.');
                $seen = [];
                foreach ($classIds as $classId) {
                    if (!is_int($classId) || isset($seen[$classId])) throw new BadRequestHttpException('Classe invalide ou dupliquée.');
                    $class = $this->db->fetchAssociative('SELECT origin, owner_id FROM character_class WHERE id = ? FOR SHARE', [$classId]);
                    if (!$class || !(($class['origin'] === 'OFFICIAL' && $class['owner_id'] === null) || (!$official && $class['origin'] === 'CUSTOM' && (int) $class['owner_id'] === $user->getId()))) throw new BadRequestHttpException('Classe indisponible.');
                    $seen[$classId] = true;
                }
                $args = [$data['slug'], $data['name'], $data['level'], $school, $data['castingTime'], $data['range'], $data['duration'], $data['description']];
                $candidate = $official ? new SpellDefinition(...$args) : SpellDefinition::forOwner($user, ...$args);
                $candidate->setOriginalName($data['originalName'])->setHigherLevels($data['higherLevels'])->setRulesVariant($data['rulesVariant'])
                    ->setSource($data['sourceCode'], $data['sourceReference'], $data['sourceUrl'])
                    ->setComponents($data['verbal'], $data['somatic'], $data['material'], $data['materialDescription'], $data['materialCostCp'], $data['materialConsumed'])
                    ->setConcentration($data['concentration'])->setRitual($data['ritual']);
                $columns = ['level' => $candidate->getLevel(), 'school' => $candidate->getSchool()->value, 'material_cost_cp' => $candidate->getMaterialCostCp()];
                foreach (self::TEXT as $field => $column) $columns[$column] = $candidate->{'get'.ucfirst($field)}();
                foreach (self::FLAGS as $field => $column) $columns[$column] = $candidate->{'is'.ucfirst($field)}() ? 'true' : 'false';
                $substitutionUse=$row!==null && $this->db->fetchOne("SELECT to_regclass('character_spell_substitution')")!==null && ($this->db->fetchOne('SELECT 1 FROM character_spell_substitution WHERE spell_id=? LIMIT 1',[$id]) || $this->db->fetchOne('SELECT 1 FROM spell_grant_substitution_rule r JOIN spell_subclass_grant g ON g.id=r.grant_id WHERE g.spell_id=? LIMIT 1',[$id]));
                if ($row !== null && ($substitutionUse || $this->db->fetchOne('SELECT 1 FROM character_spell_acquisition WHERE spell_id = ? LIMIT 1', [$id]))) {
                    foreach ($columns as $column => $value) {
                        if (in_array($column, ['name', 'original_name', 'description', 'source_code', 'source_reference', 'source_url'], true)) continue;
                        $previous = $row[$column];
                        if (in_array($column, array_values(self::FLAGS), true)) $previous = filter_var($previous, FILTER_VALIDATE_BOOL) ? 'true' : 'false';
                        if (in_array($column, ['level', 'material_cost_cp'], true) && $previous !== null) $previous = (int) $previous;
                        if ($previous !== $value) throw new ConflictHttpException('Les mécaniques de ce sort acquis sont verrouillées.');
                    }
                    $previousIds = array_map('intval', $this->db->fetchFirstColumn('SELECT character_class_id FROM spell_class_list_entry WHERE spell_id = ?', [$id]));
                    $requestedIds = $classIds;
                    sort($previousIds); sort($requestedIds);
                    if ($previousIds !== $requestedIds) throw new ConflictHttpException('Les listes de classes de ce sort acquis sont verrouillées.');
                }
                if ($row === null) {
                    $columns += ['slug' => $candidate->getSlug(), 'origin' => $official ? 'OFFICIAL' : 'CUSTOM', 'owner_id' => $official ? null : $user->getId()];
                    $names = implode(', ', array_keys($columns)); $marks = implode(', ', array_fill(0, count($columns), '?'));
                    $id = (int) $this->db->fetchOne("INSERT INTO spell_definition ($names) VALUES ($marks) RETURNING id", array_values($columns));
                } else $this->db->update('spell_definition', $columns, ['id' => $id]);
                if ($row === null || array_key_exists('classIds', $payload)) {
                    $existing = $this->db->fetchAllAssociative('SELECT id, character_class_id FROM spell_class_list_entry WHERE spell_id = ?', [$id]);
                    foreach ($existing as $entry) if (!in_array((int) $entry['character_class_id'], $classIds, true)) $this->db->delete('spell_class_list_entry', ['id' => $entry['id']]);
                    $previousIds = array_map('intval', array_column($existing, 'character_class_id'));
                    foreach ($classIds as $classId) if (!in_array($classId, $previousIds, true)) $this->db->insert('spell_class_list_entry', ['spell_id' => $id, 'character_class_id' => $classId]);
                }
                return $this->get($id, $user, $official);
            });
        } catch (UniqueConstraintViolationException $error) {
            throw new ConflictHttpException('Ce slug est déjà utilisé.', $error);
        } catch (\InvalidArgumentException $error) {
            throw new BadRequestHttpException($error->getMessage(), $error);
        }
    }

    public function delete(int $id, User $user, bool $official = false): void
    {
        try {
            $this->db->transactional(function () use ($id, $user, $official): void {
                $this->db->executeStatement('LOCK TABLE spell_definition IN ROW EXCLUSIVE MODE');
                if ($official) $this->requireAdmin($user);
                $row = $this->row($id, $user, true);
                if (($row['origin'] === 'OFFICIAL') !== $official) throw new AccessDeniedHttpException('Interface incompatible avec l’origine du sort.');
                $this->db->delete('spell_definition', ['id' => $id]);
            });
        } catch (ForeignKeyConstraintViolationException $error) {
            throw new ConflictHttpException('Ce sort est utilisé et ne peut pas être supprimé.', $error);
        }
    }
}

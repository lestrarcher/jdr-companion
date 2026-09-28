<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException, NotFoundHttpException};

/** Personal assignments; no definition creation and no runtime state mutation. */
final class CustomReferenceFeatureRuleService
{
    private const SOURCES = [
        'class' => ['character_class', 'character_class_id'],
        'subclass' => ['character_subclass', 'character_subclass_id'],
        'race' => ['character_race', 'character_race_id'],
        'feat' => ['feat', 'feat_id'],
        'progression' => ['progression_definition', 'progression_definition_id'],
    ];
    private const FIELDS = ['featureDefinitionId', 'sourceType', 'sourceId', 'unlockLevel', 'progressionThreshold'];

    public function __construct(private readonly Connection $db) {}

    public function list(User $owner): array
    {
        $rules = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT * FROM character_feature_rule WHERE origin = 'CUSTOM' AND owner_id = ? ORDER BY id",
            [$owner->getId()],
        ) as $row) {
            $rule = $this->serialize($row);
            if ($rule !== null) $rules[] = $rule;
        }
        return $rules;
    }

    public function get(int $id, User $owner): array
    {
        return $this->serialize($this->owned($id, $owner)) ?? throw new NotFoundHttpException('Attribution introuvable.');
    }

    public function create(array $payload, User $owner): array
    {
        return $this->write(function () use ($payload, $owner): array {
            $this->lockUsage();
            $data = $this->validate($payload, null, $owner);
            $this->db->insert('character_feature_rule', $this->columns($data) + [
                'origin' => 'CUSTOM', 'owner_id' => $owner->getId(), 'display_order' => 0,
            ]);
            return $this->get((int) $this->db->lastInsertId(), $owner);
        });
    }

    public function patch(int $id, array $payload, User $owner): array
    {
        return $this->write(function () use ($id, $payload, $owner): array {
            $this->get($id, $owner);
            $this->lockUsage();
            $row = $this->owned($id, $owner, true);
            $current = $this->data($row);
            $data = $this->validate($payload, $current, $owner);
            if ($data !== $current) {
                if ($this->countCharacters($row) > 0) throw new ConflictHttpException('Cette attribution est utilisée : sa structure est figée.');
                $this->db->update('character_feature_rule', $this->columns($data), ['id' => $id]);
            }
            return $this->get($id, $owner);
        });
    }

    public function delete(int $id, User $owner): void
    {
        $this->write(function () use ($id, $owner): void {
            $this->get($id, $owner);
            $this->lockUsage();
            $row = $this->owned($id, $owner, true);
            if ($this->countCharacters($row) > 0) throw new ConflictHttpException('Cette attribution est utilisée et ne peut pas être supprimée.');
            $this->db->delete('character_feature_rule', ['id' => $id]);
        });
    }

    /** Read-only diagnostic using the same policy as PATCH/DELETE. */
    public function usageCount(int $id, User $owner): int
    {
        return $this->countCharacters($this->owned($id, $owner));
    }

    private function write(callable $operation): mixed
    {
        try {
            return $this->db->transactional($operation);
        } catch (UniqueConstraintViolationException $error) {
            throw new ConflictHttpException('Cette attribution existe déjà.', $error);
        }
    }

    private function lockUsage(): void
    {
        // Same parent-first prefix as Resource/Feature CRUD. Acquisition and campaign
        // writes cannot race the usage check. No runtime writer needs to be changed.
        $this->db->executeStatement('LOCK TABLE trackable_resource_definition, character_feature_definition,
            character_feature_rule, character_class, character_subclass, character_race, feat,
            progression_definition, campaign, "character", character_class_level, character_feat,
            character_progression IN SHARE ROW EXCLUSIVE MODE');
    }

    private function owned(int $id, User $owner, bool $lock = false): array
    {
        $row = $this->db->fetchAssociative(
            "SELECT * FROM character_feature_rule WHERE id = ? AND origin = 'CUSTOM' AND owner_id = ?".($lock ? ' FOR UPDATE' : ''),
            [$id, $owner->getId()],
        );
        if ($row === false || $this->serialize($row) === null) throw new NotFoundHttpException('Attribution introuvable.');
        return $row;
    }

    private function countCharacters(array $row): int
    {
        $data = $this->data($row);
        // UNION (not ALL) also terminates if a corrupt racial ancestry contains a cycle.
        $prefix = $data['sourceType'] === 'race' ? 'WITH RECURSIVE races(id) AS (
            SELECT id FROM character_race WHERE id = :source
            UNION SELECT r.id FROM character_race r JOIN races p ON r.parent_race_id = p.id) ' : '';
        $condition = match ($data['sourceType']) {
            'class' => 'EXISTS (SELECT 1 FROM character_class_level a WHERE a.character_id = c.id AND a.character_class_id = :source)',
            'subclass' => 'EXISTS (SELECT 1 FROM character_class_level a WHERE a.character_id = c.id AND a.subclass_id = :source)',
            'race' => 'c.race_id IN (SELECT id FROM races)',
            'feat' => 'EXISTS (SELECT 1 FROM character_feat a WHERE a.character_id = c.id AND a.feat_id = :source)',
            'progression' => 'EXISTS (SELECT 1 FROM character_progression a WHERE a.character_id = c.id AND a.progression_definition_id = :source)',
        };
        return (int) $this->db->fetchOne($prefix.'SELECT count(*) FROM "character" c
            JOIN campaign p ON p.id = c.campaign_id WHERE p.owner_id = :owner AND '.$condition,
            ['owner' => $row['owner_id'], 'source' => $data['sourceId']],
        );
    }

    private function validate(array $payload, ?array $current, User $owner): array
    {
        if (array_diff(array_keys($payload), self::FIELDS) !== []) throw new BadRequestHttpException('Le payload contient un champ inconnu ou interdit.');
        $data = array_replace(array_fill_keys(self::FIELDS, null), $current ?? [], $payload);
        if (!is_string($data['sourceType']) || !isset(self::SOURCES[$data['sourceType']])) throw new BadRequestHttpException('Type de source invalide.');
        if ($current !== null && $data['sourceType'] !== $current['sourceType']) {
            // A new family must identify its own source explicitly. Never reuse an ID
            // belonging to another table or carry the previous level/threshold across.
            if (!array_key_exists('sourceId', $payload)) throw new BadRequestHttpException('La nouvelle source doit être identifiée.');
            $data['unlockLevel'] = $payload['unlockLevel'] ?? null;
            $data['progressionThreshold'] = $payload['progressionThreshold'] ?? null;
        }
        foreach (['featureDefinitionId', 'sourceId'] as $key) {
            if (!$this->integer($data[$key], 1, 2147483647)) throw new BadRequestHttpException('Les références doivent être des entiers positifs.');
        }
        if ($data['sourceType'] === 'progression') {
            if ($data['unlockLevel'] !== null || !$this->integer($data['progressionThreshold'], 0, 2147483647)) {
                throw new BadRequestHttpException('Une progression exige un seuil entier positif ou nul, sans niveau.');
            }
        } elseif (!$this->integer($data['unlockLevel'], 1, 20) || $data['progressionThreshold'] !== null) {
            throw new BadRequestHttpException('Cette source exige un niveau entier entre 1 et 20, sans seuil.');
        }
        if ($this->reference('character_feature_definition', $data['featureDefinitionId'], $owner->getId()) === null) {
            throw new BadRequestHttpException('La capacité sélectionnée est indisponible.');
        }
        if ($this->reference(self::SOURCES[$data['sourceType']][0], $data['sourceId'], $owner->getId()) === null) {
            throw new BadRequestHttpException('La source sélectionnée est indisponible.');
        }
        return $data;
    }

    private function integer(mixed $value, int $minimum, int $maximum): bool
    {
        return is_int($value) && $value >= $minimum && $value <= $maximum;
    }

    /** DBAL equivalent of ReferenceVisibility::allows, including dependency ownership.
     * Tables/columns come exclusively from internal constants; fresh reads avoid stale
     * ORM entities after a relation update in the same process. Never change parents.
     */
    private function reference(string $table, int $id, ?int $owner, array $visited = []): ?array
    {
        $key = $table.':'.$id;
        if (isset($visited[$key])) return null;
        $visited[$key] = true;
        $row = $this->db->fetchAssociative('SELECT * FROM '.$table.' WHERE id = ?', [$id]);
        if ($row === false) return null;
        if ($row['origin'] === 'OFFICIAL') {
            if ($row['owner_id'] !== null) return null;
            $dependencyOwner = null;
        } elseif ($row['origin'] === 'CUSTOM' && $owner !== null && (int) $row['owner_id'] === $owner) {
            $dependencyOwner = $owner;
        } else {
            return null;
        }
        $dependency = match ($table) {
            'character_feature_definition' => ['trackable_resource_definition', 'resource_definition_id'],
            'character_subclass' => ['character_class', 'character_class_id'],
            'character_race' => ['character_race', 'parent_race_id'],
            default => null,
        };
        if ($dependency !== null && $row[$dependency[1]] !== null
            && $this->reference($dependency[0], (int) $row[$dependency[1]], $dependencyOwner, $visited) === null) return null;
        return ['id' => (int) $row['id'], 'name' => $row['name'], 'slug' => $row['slug'], 'origin' => $row['origin']];
    }

    private function data(array $row): array
    {
        foreach (self::SOURCES as $type => [, $column]) {
            if ($row[$column] !== null) {
                return ['featureDefinitionId' => (int) $row['feature_definition_id'], 'sourceType' => $type,
                    'sourceId' => (int) $row[$column], 'unlockLevel' => $type === 'progression' ? null : (int) $row['unlock_level'],
                    'progressionThreshold' => $row['progression_threshold'] === null ? null : (int) $row['progression_threshold']];
            }
        }
        throw new \LogicException('Attribution sans source.');
    }

    private function columns(array $data): array
    {
        $columns = ['feature_definition_id' => $data['featureDefinitionId'],
            'unlock_level' => $data['unlockLevel'] ?? 1, 'progression_threshold' => $data['progressionThreshold']];
        foreach (self::SOURCES as $type => [, $column]) $columns[$column] = $data['sourceType'] === $type ? $data['sourceId'] : null;
        return $columns;
    }

    private function serialize(array $row): ?array
    {
        $data = $this->data($row);
        $owner = (int) $row['owner_id'];
        $feature = $this->reference('character_feature_definition', $data['featureDefinitionId'], $owner);
        $source = $this->reference(self::SOURCES[$data['sourceType']][0], $data['sourceId'], $owner);
        if ($feature === null || $source === null) return null;
        return ['id' => (int) $row['id'], 'featureDefinition' => $feature,
            'source' => ['type' => $data['sourceType']] + $source,
            'unlockLevel' => $data['unlockLevel'], 'progressionThreshold' => $data['progressionThreshold']];
    }
}

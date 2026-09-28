<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\{Ability, ResourceMaximumType, ResourceRechargeType};
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException, NotFoundHttpException};

/** Personal resource definitions only; no assignment or runtime mutation. */
final class CustomReferenceResourceService
{
    private const FIELDS = [
        'name' => 'name', 'description' => 'description',
        'rechargeType' => 'recharge_type', 'maximumType' => 'maximum_type',
        'baseMaximum' => 'base_maximum', 'multiplier' => 'multiplier',
        'minimumMaximum' => 'minimum_maximum', 'scalingAbility' => 'scaling_ability',
    ];
    private const MECHANICS = ['rechargeType', 'maximumType', 'baseMaximum', 'multiplier', 'minimumMaximum', 'scalingAbility'];

    public function __construct(private readonly Connection $db) {}

    public function list(User $owner): array
    {
        return array_map($this->serialize(...), $this->db->fetchAllAssociative(
            "SELECT * FROM trackable_resource_definition WHERE origin = 'CUSTOM' AND owner_id = ? ORDER BY name, id",
            [$owner->getId()],
        ));
    }

    public function get(int $id, User $owner): array
    {
        return $this->serialize($this->owned($id, $owner));
    }

    public function create(array $payload, User $owner): array
    {
        $data = $this->validate($payload, [
            'name' => '', 'description' => null, 'rechargeType' => 'none',
            'maximumType' => 'fixed', 'baseMaximum' => 0, 'multiplier' => 1,
            'minimumMaximum' => 0, 'scalingAbility' => null,
        ]);
        try {
            return $this->db->transactional(function () use ($data, $owner): array {
                $row = $this->columns($data);
                $row += [
                    'origin' => 'CUSTOM', 'owner_id' => $owner->getId(),
                    'slug' => 'custom-'.bin2hex(random_bytes(16)), 'custom' => 'true',
                    'created_at' => $this->now(), 'updated_at' => $this->now(),
                ];
                $columns = implode(', ', array_keys($row));
                $placeholders = implode(', ', array_fill(0, count($row), '?'));
                $created = $this->db->fetchAssociative(
                    "INSERT INTO trackable_resource_definition ($columns) VALUES ($placeholders) RETURNING *",
                    array_values($row),
                );
                return $this->serialize($created);
            });
        } catch (UniqueConstraintViolationException $error) {
            // The unique index is the authority, not a SELECT before INSERT.
            throw new ConflictHttpException('La création a rencontré un conflit. Réessayez.', $error);
        }
    }

    public function patch(int $id, array $payload, User $owner): array
    {
        return $this->db->transactional(function () use ($id, $payload, $owner): array {
            $this->owned($id, $owner); // Hide inaccessible IDs before validating the payload.
            $this->lockUsage();
            $row = $this->owned($id, $owner);
            $current = $this->serialize($row);
            $data = $this->validate($payload, $current);
            $mechanicalChange = false;
            foreach (self::MECHANICS as $key) {
                $mechanicalChange = $mechanicalChange || $data[$key] !== $current[$key];
            }
            if ($mechanicalChange && $this->isUsed($row, $owner)) {
                throw new ConflictHttpException('Cette ressource est utilisée : ses paramètres mécaniques sont figés.');
            }
            $this->db->update('trackable_resource_definition',
                $this->columns($data) + ['updated_at' => $this->now()], ['id' => $id]);
            return $this->get($id, $owner);
        });
    }

    public function delete(int $id, User $owner): void
    {
        $this->db->transactional(function () use ($id, $owner): void {
            $this->owned($id, $owner);
            $this->lockUsage();
            $row = $this->owned($id, $owner);
            if ($this->isUsed($row, $owner)) {
                throw new ConflictHttpException('Cette ressource est utilisée et ne peut pas être supprimée.');
            }
            $this->db->delete('trackable_resource_definition', ['id' => $id]);
        });
    }

    private function owned(int $id, User $owner): array
    {
        $row = $this->db->fetchAssociative(
            "SELECT * FROM trackable_resource_definition WHERE id = ? AND origin = 'CUSTOM' AND owner_id = ?",
            [$id, $owner->getId()],
        );
        if ($row === false) throw new NotFoundHttpException('Ressource introuvable.');
        return $row;
    }

    private function lockUsage(): void
    {
        // Existing FKs cascade/set null. Serialize usage checks with writes, including
        // JSON state writes, without changing those writers or any FK/runtime behavior.
        // The locks live only until this short mutation transaction completes.
        $this->db->executeStatement(
            'LOCK TABLE trackable_resource_definition, character_feature_definition, trackable_resource_rule, character_session_state, "character" IN SHARE ROW EXCLUSIVE MODE',
        );
    }

    private function isUsed(array $resource, User $owner): bool
    {
        // Any inbound FK blocks, including an inconsistent foreign-owner reference.
        // No details about that reference are returned to the caller.
        foreach (['character_feature_definition', 'trackable_resource_rule'] as $table) {
            if ($this->db->fetchOne("SELECT 1 FROM $table WHERE resource_definition_id = ? LIMIT 1", [$resource['id']])) return true;
        }
        // Campaign ownership comes from the session for historical state.
        // Also protect resources explicitly configured in legacy character definitions.
        $documents = $this->db->fetchAllAssociative(
            'SELECT s.state AS document, c.owner_id FROM character_session_state s
             JOIN game_session g ON g.id = s.game_session_id JOIN campaign c ON c.id = g.campaign_id
             UNION ALL
             SELECT ch.definition AS document, c.owner_id FROM "character" ch JOIN campaign c ON c.id = ch.campaign_id',
        );
        foreach ($documents as $document) {
            $data = json_decode($document['document'], true, 512, JSON_THROW_ON_ERROR);
            $resources = is_array($data) ? ($data['resources'] ?? []) : null;
            if (!is_array($resources)) {
                if ((int) $document['owner_id'] === $owner->getId()) return true;
                continue;
            }
            foreach ($resources as $entry) {
                if (is_array($entry) && ($entry['id'] ?? null) === $resource['slug']) return true;
                if ((!is_array($entry) || !is_string($entry['id'] ?? null))
                    && (int) $document['owner_id'] === $owner->getId()) return true;
            }
        }
        return false;
    }

    private function validate(array $payload, array $current): array
    {
        if (array_diff(array_keys($payload), array_keys(self::FIELDS)) !== []) {
            throw new BadRequestHttpException('Le payload contient un champ inconnu ou interdit.');
        }
        $data = array_intersect_key(array_replace($current, $payload), self::FIELDS);
        if (!is_string($data['name']) || trim($data['name']) === '' || mb_strlen(trim($data['name'])) > 150) {
            throw new BadRequestHttpException('Le nom doit contenir entre 1 et 150 caractères.');
        }
        $data['name'] = trim($data['name']);
        if ($data['description'] !== null && (!is_string($data['description']) || str_contains($data['description'], "\0"))) {
            throw new BadRequestHttpException('La description doit être une chaîne ou null.');
        }
        if (str_contains($data['name'], "\0")) throw new BadRequestHttpException('Le nom contient un caractère invalide.');
        if ($data['description'] !== null) {
            $data['description'] = trim($data['description']);
            if ($data['description'] === '') $data['description'] = null;
        }
        foreach (['baseMaximum' => 0, 'multiplier' => 1, 'minimumMaximum' => 0] as $key => $minimum) {
            if (!is_int($data[$key]) || $data[$key] < $minimum || $data[$key] > 2147483647) {
                throw new BadRequestHttpException("Valeur entière invalide pour $key.");
            }
        }
        if (!is_string($data['rechargeType']) || ResourceRechargeType::tryFrom($data['rechargeType']) === null
            || !is_string($data['maximumType']) || ResourceMaximumType::tryFrom($data['maximumType']) === null) {
            throw new BadRequestHttpException('Type de maximum ou de recharge invalide.');
        }
        if ($data['maximumType'] === ResourceMaximumType::AbilityModifier->value) {
            if (!is_string($data['scalingAbility']) || Ability::tryFrom($data['scalingAbility']) === null) {
                throw new BadRequestHttpException('Une caractéristique valide est requise.');
            }
        } elseif ($data['scalingAbility'] !== null) {
            throw new BadRequestHttpException('Ce type de maximum ne peut pas utiliser une caractéristique.');
        }
        return $data;
    }

    private function serialize(array $row): array
    {
        $data = ['id' => (int) $row['id'], 'slug' => $row['slug']];
        foreach (self::FIELDS as $field => $column) $data[$field] = $row[$column];
        foreach (['baseMaximum', 'multiplier', 'minimumMaximum'] as $field) $data[$field] = (int) $data[$field];
        return $data;
    }

    private function columns(array $data): array
    {
        $row = [];
        foreach (self::FIELDS as $field => $column) $row[$column] = $data[$field];
        return $row;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}

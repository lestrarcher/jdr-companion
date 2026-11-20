<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/** Lecture des attaques naturelles structurées ; les valeurs ne sont jamais recalculées sur le druide. */
final readonly class BeastNaturalWeaponCatalogue
{
    public function __construct(private Connection $db) {}

    /** @return list<array<string, mixed>> */
    public function forBeast(int $beastId): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT id, name, attack_type, attack_bonus, damage_components, description, sort_order FROM beast_natural_weapon WHERE beast_id = :id ORDER BY sort_order, id', ['id' => $beastId]);
        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'attackType' => $row['attack_type'],
                'attackBonus' => $row['attack_bonus'] === null ? null : (int) $row['attack_bonus'],
                'damageComponents' => json_decode((string) $row['damage_components'], true, 512, JSON_THROW_ON_ERROR),
                'description' => $row['description'],
                'sortOrder' => (int) $row['sort_order'],
            ];
        }, $rows);
    }
}

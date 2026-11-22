<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Permanent feature HP bonuses: draconic resilience and hill dwarf toughness; no character state or history changes.';
    }

    public function up(Schema $schema): void
    {
        // Review existing raw gains/manual compensations before applying to a populated database.
        $this->addSql('ALTER TABLE character_feature_definition ADD hit_point_effects JSON DEFAULT NULL');
        $this->addSql("ALTER TABLE character_feature_definition ADD CONSTRAINT chk_feature_hp_effects CHECK (hit_point_effects IS NULL OR json_typeof(hit_point_effects) = 'array')");
        $this->addSql(<<<'SQL'
UPDATE character_feature_definition f
SET hit_point_effects = '[{"key":"resilience","label":"Résistance draconique","type":"per-class-level","value":1,"classSlug":"sorcerer"}]', updated_at = NOW()
WHERE f.slug = 'draconic-resilience' AND f.origin = 'OFFICIAL' AND f.owner_id IS NULL
AND f.activation_type = 'passive' AND f.hit_point_effects IS NULL
AND EXISTS (SELECT 1 FROM character_feature_rule r
    JOIN character_subclass s ON s.id = r.character_subclass_id
    JOIN character_class c ON c.id = s.character_class_id
    WHERE r.feature_definition_id = f.id AND r.unlock_level = 1
    AND s.slug = 'draconic-bloodline' AND c.slug = 'sorcerer'
    AND r.origin = 'OFFICIAL' AND r.owner_id IS NULL
    AND s.origin = 'OFFICIAL' AND s.owner_id IS NULL AND c.origin = 'OFFICIAL' AND c.owner_id IS NULL)
SQL);
        $this->addSql(<<<'SQL'
UPDATE character_feature_definition f
SET hit_point_effects = '[{"key":"toughness","label":"Robustesse naine","type":"per-total-level","value":1}]', updated_at = NOW()
WHERE f.slug = 'racial-dwarf-hill-phb-dwarven-toughness-1' AND f.origin = 'OFFICIAL' AND f.owner_id IS NULL
AND f.activation_type = 'passive' AND f.hit_point_effects IS NULL
AND EXISTS (SELECT 1 FROM character_feature_rule r JOIN character_race race ON race.id = r.character_race_id
    WHERE r.feature_definition_id = f.id AND r.unlock_level = 1 AND race.slug = 'dwarf-hill-phb'
    AND r.origin = 'OFFICIAL' AND r.owner_id IS NULL AND race.origin = 'OFFICIAL' AND race.owner_id IS NULL)
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve configured permanent HP effects.');
    }
}

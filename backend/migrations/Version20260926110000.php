<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relie gem-flight-use au Vol cristallin racial canonique, sans modifier son maximum ni sa recharge.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DO $$
DECLARE
    pool_id INTEGER;
    legacy_id INTEGER;
    canonical_id INTEGER;
    race_id INTEGER;
BEGIN
    SELECT id INTO pool_id FROM trackable_resource_definition WHERE slug = 'gem-flight-use';
    SELECT id INTO legacy_id FROM character_feature_definition WHERE slug = 'gem-flight';
    SELECT id INTO canonical_id FROM character_feature_definition WHERE slug = 'racial-dragonborn-gem-ftd-gem-flight-5';
    SELECT id INTO race_id FROM character_race WHERE slug = 'dragonborn-gem-ftd';
    IF pool_id IS NULL OR legacy_id IS NULL OR canonical_id IS NULL OR race_id IS NULL THEN
        RAISE EXCEPTION 'Gem Flight: missing stable resource, feature or race identity';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM character_race WHERE id = race_id AND parent_race_id IS NULL) THEN
        RAISE EXCEPTION 'Gem Flight: unexpected canonical race ancestry';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM trackable_resource_definition WHERE id = pool_id
        AND maximum_type = 'fixed' AND base_maximum = 1 AND minimum_maximum = 0
        AND multiplier = 1 AND scaling_ability IS NULL AND recharge_type = 'long-rest') THEN
        RAISE EXCEPTION 'Gem Flight: unexpected maximum or recharge';
    END IF;
    IF EXISTS (SELECT 1 FROM trackable_resource_rule WHERE resource_definition_id = pool_id) THEN
        RAISE EXCEPTION 'Gem Flight: unexpected independent resource rule';
    END IF;
    IF (SELECT count(*) FROM character_feature_rule WHERE feature_definition_id = canonical_id) <> 1
        OR NOT EXISTS (SELECT 1 FROM character_feature_rule WHERE feature_definition_id = canonical_id
            AND character_race_id = race_id AND unlock_level = 5
            AND character_class_id IS NULL AND character_subclass_id IS NULL AND feat_id IS NULL
            AND progression_definition_id IS NULL AND progression_threshold IS NULL) THEN
        RAISE EXCEPTION 'Gem Flight: expected unique canonical racial assignment at level 5';
    END IF;
    IF EXISTS (SELECT 1 FROM character_feature_rule WHERE feature_definition_id = legacy_id) THEN
        RAISE EXCEPTION 'Gem Flight: historical feature is still assigned';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_constraint WHERE contype = 'f'
        AND confrelid = 'character_feature_definition'::regclass
        AND conrelid <> 'character_feature_rule'::regclass) THEN
        RAISE EXCEPTION 'Gem Flight: unexpected incoming feature relation';
    END IF;
    IF EXISTS (SELECT 1 FROM character_feature_definition WHERE resource_definition_id = pool_id
            AND id NOT IN (legacy_id, canonical_id))
        OR EXISTS (SELECT 1 FROM character_feature_definition WHERE id IN (legacy_id, canonical_id)
            AND resource_definition_id IS NOT NULL AND resource_definition_id <> pool_id)
        OR (SELECT count(*) FROM character_feature_definition WHERE id IN (legacy_id, canonical_id)
            AND resource_definition_id = pool_id) <> 1 THEN
        RAISE EXCEPTION 'Gem Flight: ambiguous resource provider';
    END IF;

    UPDATE character_feature_definition SET resource_definition_id = NULL
        WHERE id = legacy_id AND resource_definition_id IS NOT NULL;
    UPDATE character_feature_definition SET resource_definition_id = pool_id
        WHERE id = canonical_id AND resource_definition_id IS DISTINCT FROM pool_id;
END $$
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Le lien canonique peut préexister ; ne pas réactiver automatiquement la capacité historique.');
    }
}

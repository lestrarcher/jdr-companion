<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relie le pool Imposition des mains à lay-on-hands et fixe ses paliers à cinq points par niveau de paladin.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DO $$
DECLARE
    pool_id INTEGER;
    paladin_id INTEGER;
    canonical_id INTEGER;
    legacy_id INTEGER;
BEGIN
    SELECT id INTO pool_id FROM trackable_resource_definition WHERE slug = 'utilisations-d-imposition-des-mains';
    SELECT id INTO paladin_id FROM character_class WHERE slug = 'paladin';
    SELECT id INTO canonical_id FROM character_feature_definition WHERE slug = 'lay-on-hands';
    SELECT id INTO legacy_id FROM character_feature_definition WHERE slug = 'imposition-des-mains';
    IF pool_id IS NULL OR paladin_id IS NULL OR canonical_id IS NULL OR legacy_id IS NULL THEN
        RAISE EXCEPTION 'Lay on Hands: missing stable resource, class or feature identity';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM trackable_resource_definition WHERE id = pool_id
        AND maximum_type = 'fixed' AND base_maximum = 5 AND minimum_maximum = 5
        AND multiplier = 1 AND scaling_ability IS NULL AND recharge_type = 'long-rest') THEN
        RAISE EXCEPTION 'Lay on Hands: unexpected pool maximum or recharge configuration';
    END IF;
    IF EXISTS (SELECT 1 FROM character_feature_definition WHERE id IN (canonical_id, legacy_id)
        AND resource_definition_id IS NOT NULL AND resource_definition_id <> pool_id)
        OR EXISTS (SELECT 1 FROM character_feature_definition WHERE resource_definition_id = pool_id
            AND id NOT IN (canonical_id, legacy_id)) THEN
        RAISE EXCEPTION 'Lay on Hands: unexpected resource link';
    END IF;
    IF (SELECT count(*) FROM character_feature_rule WHERE feature_definition_id = canonical_id) <> 1
        OR NOT EXISTS (SELECT 1 FROM character_feature_rule WHERE feature_definition_id = canonical_id
            AND character_class_id = paladin_id AND unlock_level = 1
            AND character_subclass_id IS NULL AND character_race_id IS NULL AND feat_id IS NULL
            AND progression_definition_id IS NULL AND progression_threshold IS NULL) THEN
        RAISE EXCEPTION 'Lay on Hands: expected canonical paladin assignment at level 1';
    END IF;
    IF EXISTS (SELECT 1 FROM character_feature_rule WHERE feature_definition_id = legacy_id) THEN
        RAISE EXCEPTION 'Lay on Hands: legacy feature is still assigned; review before unlinking';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_constraint WHERE contype = 'f'
        AND confrelid = 'character_feature_definition'::regclass
        AND conrelid <> 'character_feature_rule'::regclass) THEN
        RAISE EXCEPTION 'Lay on Hands: unexpected incoming feature relation; review before unlinking';
    END IF;
    IF EXISTS (SELECT 1 FROM trackable_resource_rule WHERE resource_definition_id = pool_id
        AND (character_class_id IS DISTINCT FROM paladin_id OR unlock_level NOT BETWEEN 1 AND 20
            OR maximum_override IS DISTINCT FROM 5 * unlock_level OR maximum_bonus <> 0
            OR character_subclass_id IS NOT NULL OR character_race_id IS NOT NULL OR feat_id IS NOT NULL)) THEN
        RAISE EXCEPTION 'Lay on Hands: conflicting resource tier or origin';
    END IF;

    UPDATE character_feature_definition SET resource_definition_id = pool_id
        WHERE id = canonical_id AND resource_definition_id IS DISTINCT FROM pool_id;
    -- Retain the historical definition and all its editorial fields, but no runtime grant.
    UPDATE character_feature_definition SET resource_definition_id = NULL
        WHERE id = legacy_id AND resource_definition_id IS NOT NULL;

    -- Level 1 uses the existing base of 5. The resolver selects the highest applicable
    -- class-level override, never total character level. This covers the model's 1..20 range.
    INSERT INTO trackable_resource_rule
        (resource_definition_id, character_class_id, character_subclass_id, character_race_id,
         feat_id, unlock_level, maximum_override, maximum_bonus)
    SELECT pool_id, paladin_id, NULL, NULL, NULL, tier, 5 * tier, 0
    FROM generate_series(2, 20) AS levels(tier)
    WHERE NOT EXISTS (SELECT 1 FROM trackable_resource_rule
        WHERE resource_definition_id = pool_id AND character_class_id = paladin_id AND unlock_level = tier);
END $$
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les liens ou paliers corrects peuvent préexister ; leur retrait doit être décidé explicitement.');
    }
}

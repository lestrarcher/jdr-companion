<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Consolide huit doublons historiques de capacités sous leurs définitions canoniques sans perdre règles ni ressources.';
    }

    public function up(Schema $schema): void
    {
        $platformClass = $this->connection->getDatabasePlatform()::class;
        $this->abortIf(!str_contains($platformClass, 'PostgreSQL'), 'Migration réservée à PostgreSQL.');

        $this->addSql(<<<'SQL'
DO $$
DECLARE
  pair RECORD;
  source_id INT;
  target_id INT;
  source_count INT;
  target_count INT;
  source_custom BOOLEAN;
  target_custom BOOLEAN;
  source_resource_id INT;
  target_resource_id INT;
  conflicting_rules INT;
BEGIN
  FOR pair IN
    SELECT * FROM (VALUES
      ('lien-avec-une-arme', 'eldritch-knight-weapon-bond'),
      ('frappe-occulte', 'eldritch-knight-eldritch-strike'),
      ('charge-arcanique', 'eldritch-knight-arcane-charge'),
      ('magie-de-guerre-amelioree', 'eldritch-knight-improved-war-magic'),
      ('monster-slayer-hunter-s-sense', 'hunters-sense'),
      ('storm-sorcery-storm-s-fury', 'storms-fury'),
      ('divination-experte', 'divination-expert-divination'),
      ('troisieme-oeil', 'divination-the-third-eye')
    ) AS pairs(source_slug, target_slug)
  LOOP
    SELECT count(*) INTO source_count FROM character_feature_definition WHERE slug = pair.source_slug;
    SELECT count(*) INTO target_count FROM character_feature_definition WHERE slug = pair.target_slug;

    IF target_count <> 1 THEN
      RAISE EXCEPTION 'Expected exactly one canonical feature %, found %', pair.target_slug, target_count;
    END IF;
    IF source_count = 0 THEN
      CONTINUE;
    END IF;
    IF source_count <> 1 THEN
      RAISE EXCEPTION 'Expected at most one historical feature %, found %', pair.source_slug, source_count;
    END IF;

    SELECT id, custom, resource_definition_id
      INTO STRICT source_id, source_custom, source_resource_id
      FROM character_feature_definition WHERE slug = pair.source_slug;
    SELECT id, custom, resource_definition_id
      INTO STRICT target_id, target_custom, target_resource_id
      FROM character_feature_definition WHERE slug = pair.target_slug;

    IF source_custom OR target_custom THEN
      RAISE EXCEPTION 'Refusing to consolidate custom feature % into %', pair.source_slug, pair.target_slug;
    END IF;
    IF source_resource_id IS NOT NULL AND target_resource_id IS NOT NULL AND source_resource_id <> target_resource_id THEN
      RAISE EXCEPTION 'Conflicting resources while consolidating % into %', pair.source_slug, pair.target_slug;
    END IF;

    SELECT count(*) INTO conflicting_rules
    FROM character_feature_rule source_rule
    JOIN character_feature_rule target_rule
      ON target_rule.feature_definition_id = target_id
     AND target_rule.character_class_id IS NOT DISTINCT FROM source_rule.character_class_id
     AND target_rule.character_subclass_id IS NOT DISTINCT FROM source_rule.character_subclass_id
     AND target_rule.character_race_id IS NOT DISTINCT FROM source_rule.character_race_id
     AND target_rule.feat_id IS NOT DISTINCT FROM source_rule.feat_id
     AND target_rule.progression_definition_id IS NOT DISTINCT FROM source_rule.progression_definition_id
     AND target_rule.unlock_level = source_rule.unlock_level
     AND target_rule.progression_threshold IS NOT DISTINCT FROM source_rule.progression_threshold
    WHERE source_rule.feature_definition_id = source_id
      AND target_rule.display_order IS DISTINCT FROM source_rule.display_order;
    IF conflicting_rules <> 0 THEN
      RAISE EXCEPTION 'Non-equivalent CharacterFeatureRule collision while consolidating % into %', pair.source_slug, pair.target_slug;
    END IF;

    DELETE FROM character_feature_rule source_rule
    USING character_feature_rule target_rule
    WHERE source_rule.feature_definition_id = source_id
      AND target_rule.feature_definition_id = target_id
      AND target_rule.character_class_id IS NOT DISTINCT FROM source_rule.character_class_id
      AND target_rule.character_subclass_id IS NOT DISTINCT FROM source_rule.character_subclass_id
      AND target_rule.character_race_id IS NOT DISTINCT FROM source_rule.character_race_id
      AND target_rule.feat_id IS NOT DISTINCT FROM source_rule.feat_id
      AND target_rule.progression_definition_id IS NOT DISTINCT FROM source_rule.progression_definition_id
      AND target_rule.unlock_level = source_rule.unlock_level
      AND target_rule.progression_threshold IS NOT DISTINCT FROM source_rule.progression_threshold
      AND target_rule.display_order = source_rule.display_order;

    UPDATE character_feature_rule
       SET feature_definition_id = target_id
     WHERE feature_definition_id = source_id;

    IF target_resource_id IS NULL AND source_resource_id IS NOT NULL THEN
      UPDATE character_feature_definition
         SET resource_definition_id = source_resource_id, updated_at = CURRENT_TIMESTAMP
       WHERE id = target_id;
    END IF;

    IF EXISTS (SELECT 1 FROM character_feature_rule WHERE feature_definition_id = source_id) THEN
      RAISE EXCEPTION 'CharacterFeatureRule references remain on historical feature %', pair.source_slug;
    END IF;
    DELETE FROM character_feature_definition WHERE id = source_id;
  END LOOP;
END $$
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('La consolidation peut dédupliquer des règles équivalentes et ne peut pas être inversée sans ambiguïté.');
    }
}

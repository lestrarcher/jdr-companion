<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalise genie-dao, War Magic et le doublon historique du Chevalier occulte sans changer leurs identités utiles.';
    }

    public function up(Schema $schema): void
    {
        $platformClass = $this->connection->getDatabasePlatform()::class;
        $this->abortIf(!str_contains($platformClass, 'PostgreSQL'), 'Migration réservée à PostgreSQL.');

        $this->addSql(<<<'SQL'
DO $$
DECLARE warlock_id INT; source_id INT; canonical_count INT;
BEGIN
  SELECT id INTO STRICT warlock_id FROM character_class WHERE slug = 'warlock';
  SELECT id INTO STRICT source_id FROM character_subclass WHERE character_class_id = warlock_id AND slug = 'genie-dao' AND custom = false;
  SELECT count(*) INTO canonical_count FROM character_subclass WHERE character_class_id = warlock_id AND slug = 'genie';
  IF canonical_count <> 0 THEN RAISE EXCEPTION 'Ambiguous warlock/genie normalization: canonical subclass already exists'; END IF;
  UPDATE character_subclass SET slug = 'genie', updated_at = CURRENT_TIMESTAMP WHERE id = source_id;
END $$
SQL);

        $this->addSql(<<<'SQL'
DO $$
DECLARE source_id INT; canonical_count INT; matching_rules INT;
BEGIN
  SELECT id INTO STRICT source_id FROM character_feature_definition WHERE slug = 'magie-de-guerre' AND custom = false;
  SELECT count(*) INTO canonical_count FROM character_feature_definition WHERE slug = 'eldritch-knight-war-magic';
  IF canonical_count <> 0 THEN RAISE EXCEPTION 'Ambiguous War Magic normalization: canonical feature already exists'; END IF;
  SELECT count(*) INTO matching_rules
  FROM character_feature_rule r
  JOIN character_subclass s ON s.id = r.character_subclass_id
  JOIN character_class c ON c.id = s.character_class_id
  WHERE r.feature_definition_id = source_id AND c.slug = 'fighter' AND s.slug = 'eldritch-knight' AND r.unlock_level = 7;
  IF matching_rules <> 1 THEN RAISE EXCEPTION 'Historical magie-de-guerre does not uniquely match fighter/eldritch-knight level 7'; END IF;
  UPDATE character_feature_definition SET slug = 'eldritch-knight-war-magic', updated_at = CURRENT_TIMESTAMP WHERE id = source_id;
END $$
SQL);

        $this->addSql(<<<'SQL'
DO $$
DECLARE fighter_id INT; source_id INT; target_id INT; bad_collisions INT;
BEGIN
  SELECT id INTO STRICT fighter_id FROM character_class WHERE slug = 'fighter';
  SELECT id INTO STRICT source_id FROM character_subclass WHERE character_class_id = fighter_id AND slug = 'chevalier-occulte' AND custom = false;
  SELECT id INTO STRICT target_id FROM character_subclass WHERE character_class_id = fighter_id AND slug = 'eldritch-knight' AND custom = false;

  SELECT count(*) INTO bad_collisions
  FROM character_feature_rule source_rule
  JOIN character_feature_rule target_rule
    ON target_rule.character_subclass_id = target_id
   AND target_rule.feature_definition_id = source_rule.feature_definition_id
   AND target_rule.unlock_level = source_rule.unlock_level
  WHERE source_rule.character_subclass_id = source_id
    AND target_rule.display_order IS DISTINCT FROM source_rule.display_order;
  IF bad_collisions <> 0 THEN RAISE EXCEPTION 'Non-equivalent CharacterFeatureRule collision while merging chevalier-occulte'; END IF;

  SELECT count(*) INTO bad_collisions
  FROM trackable_resource_rule source_rule
  JOIN trackable_resource_rule target_rule
    ON target_rule.character_subclass_id = target_id
   AND target_rule.resource_definition_id = source_rule.resource_definition_id
   AND target_rule.unlock_level = source_rule.unlock_level
  WHERE source_rule.character_subclass_id = source_id
    AND (target_rule.maximum_override IS DISTINCT FROM source_rule.maximum_override
      OR target_rule.maximum_bonus IS DISTINCT FROM source_rule.maximum_bonus);
  IF bad_collisions <> 0 THEN RAISE EXCEPTION 'Non-equivalent TrackableResourceRule collision while merging chevalier-occulte'; END IF;

  UPDATE character_class_level SET subclass_id = target_id WHERE subclass_id = source_id;

  DELETE FROM character_feature_rule source_rule
  USING character_feature_rule target_rule
  WHERE source_rule.character_subclass_id = source_id
    AND target_rule.character_subclass_id = target_id
    AND target_rule.feature_definition_id = source_rule.feature_definition_id
    AND target_rule.unlock_level = source_rule.unlock_level
    AND target_rule.display_order = source_rule.display_order;
  UPDATE character_feature_rule SET character_subclass_id = target_id WHERE character_subclass_id = source_id;

  DELETE FROM trackable_resource_rule source_rule
  USING trackable_resource_rule target_rule
  WHERE source_rule.character_subclass_id = source_id
    AND target_rule.character_subclass_id = target_id
    AND target_rule.resource_definition_id = source_rule.resource_definition_id
    AND target_rule.unlock_level = source_rule.unlock_level
    AND target_rule.maximum_override IS NOT DISTINCT FROM source_rule.maximum_override
    AND target_rule.maximum_bonus = source_rule.maximum_bonus;
  UPDATE trackable_resource_rule SET character_subclass_id = target_id WHERE character_subclass_id = source_id;

  IF EXISTS (SELECT 1 FROM character_class_level WHERE subclass_id = source_id)
    OR EXISTS (SELECT 1 FROM character_feature_rule WHERE character_subclass_id = source_id)
    OR EXISTS (SELECT 1 FROM trackable_resource_rule WHERE character_subclass_id = source_id)
  THEN RAISE EXCEPTION 'References remain on fighter/chevalier-occulte'; END IF;
  DELETE FROM character_subclass WHERE id = source_id;
END $$
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('La fusion défensive peut dédupliquer des règles équivalentes et ne peut pas être inversée sans ambiguïté.');
    }
}

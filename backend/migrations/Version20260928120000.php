<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope reference assignment uniqueness by owner; enforce subclass namespaces and single rule sources without changing data.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL required.');

        // These existing invariants are prerequisites, not recreated here.
        foreach ([
            'character_class', 'character_subclass', 'character_race', 'feat',
            'character_feature_definition', 'trackable_resource_definition',
            'progression_definition', 'character_action_definition',
            'character_feature_rule', 'trackable_resource_rule', 'character_action_class_rule',
        ] as $table) {
            $this->abortIf(!(bool) $this->connection->fetchOne(
                "SELECT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = to_regclass(?) AND conname = ? AND contype = 'c' AND convalidated)",
                [$table, 'chk_'.$table.'_origin_owner'],
            ), 'Missing validated origin/owner constraint on '.$table);
        }

        // Validating CHECKs and unique indexes fail atomically on incompatible data.
        // Lock before DDL so no concurrent writer can invalidate the preflight.
        $this->addSql("LOCK TABLE character_subclass, character_feature_rule, trackable_resource_rule, character_action_class_rule IN ACCESS EXCLUSIVE MODE");
        $this->addSql("ALTER TABLE character_subclass ADD CONSTRAINT chk_subclass_slug_namespace CHECK ((origin = 'OFFICIAL' AND slug NOT LIKE 'custom-%' AND slug <> 'arch-hag') OR (origin = 'CUSTOM' AND (slug ~ '^custom-[0-9a-f]{32}$' OR slug = 'arch-hag')))");
        $this->addSql("CREATE UNIQUE INDEX uniq_subclass_custom_slug ON character_subclass (slug) WHERE origin = 'CUSTOM'");
        $this->addSql("ALTER TABLE character_feature_rule ADD CONSTRAINT chk_feature_rule_one_source CHECK (num_nonnulls(character_class_id, character_subclass_id, character_race_id, feat_id, progression_definition_id) = 1)");
        $this->addSql("ALTER TABLE character_feature_rule ADD CONSTRAINT chk_feature_rule_threshold CHECK ((progression_definition_id IS NULL AND progression_threshold IS NULL) OR (progression_definition_id IS NOT NULL AND progression_threshold IS NOT NULL AND progression_threshold >= 0))");
        $this->addSql("ALTER TABLE trackable_resource_rule ADD CONSTRAINT chk_resource_rule_one_source CHECK (num_nonnulls(character_class_id, character_subclass_id, character_race_id, feat_id) = 1)");
        $this->addSql("DROP INDEX uniq_class_feature_level");
        $this->addSql("CREATE UNIQUE INDEX uniq_class_feature_level_off ON character_feature_rule (character_class_id, feature_definition_id, unlock_level) WHERE origin = 'OFFICIAL' AND character_class_id IS NOT NULL");
        $this->addSql("CREATE UNIQUE INDEX uniq_class_feature_level_own ON character_feature_rule (owner_id, character_class_id, feature_definition_id, unlock_level) WHERE origin = 'CUSTOM' AND character_class_id IS NOT NULL");
        $this->addSql("DROP INDEX uniq_subclass_feature_level");
        $this->addSql("CREATE UNIQUE INDEX uniq_subclass_feature_level_off ON character_feature_rule (character_subclass_id, feature_definition_id, unlock_level) WHERE origin = 'OFFICIAL' AND character_subclass_id IS NOT NULL");
        $this->addSql("CREATE UNIQUE INDEX uniq_subclass_feature_level_own ON character_feature_rule (owner_id, character_subclass_id, feature_definition_id, unlock_level) WHERE origin = 'CUSTOM' AND character_subclass_id IS NOT NULL");
        $this->addSql("DROP INDEX uniq_race_feature_level");
        $this->addSql("CREATE UNIQUE INDEX uniq_race_feature_level_off ON character_feature_rule (character_race_id, feature_definition_id, unlock_level) WHERE origin = 'OFFICIAL' AND character_race_id IS NOT NULL");
        $this->addSql("CREATE UNIQUE INDEX uniq_race_feature_level_own ON character_feature_rule (owner_id, character_race_id, feature_definition_id, unlock_level) WHERE origin = 'CUSTOM' AND character_race_id IS NOT NULL");
        $this->addSql("DROP INDEX uniq_feat_feature_level");
        $this->addSql("CREATE UNIQUE INDEX uniq_feat_feature_level_off ON character_feature_rule (feat_id, feature_definition_id, unlock_level) WHERE origin = 'OFFICIAL' AND feat_id IS NOT NULL");
        $this->addSql("CREATE UNIQUE INDEX uniq_feat_feature_level_own ON character_feature_rule (owner_id, feat_id, feature_definition_id, unlock_level) WHERE origin = 'CUSTOM' AND feat_id IS NOT NULL");
        $this->addSql("DROP INDEX uniq_progression_feature_threshold");
        $this->addSql("CREATE UNIQUE INDEX uniq_progression_feature_threshold_off ON character_feature_rule (progression_definition_id, feature_definition_id, progression_threshold) WHERE origin = 'OFFICIAL' AND progression_definition_id IS NOT NULL");
        $this->addSql("CREATE UNIQUE INDEX uniq_progression_feature_threshold_own ON character_feature_rule (owner_id, progression_definition_id, feature_definition_id, progression_threshold) WHERE origin = 'CUSTOM' AND progression_definition_id IS NOT NULL");
        $this->addSql("DROP INDEX uniq_class_resource_level");
        $this->addSql("CREATE UNIQUE INDEX uniq_class_resource_level_off ON trackable_resource_rule (character_class_id, resource_definition_id, unlock_level) WHERE origin = 'OFFICIAL' AND character_class_id IS NOT NULL");
        $this->addSql("CREATE UNIQUE INDEX uniq_class_resource_level_own ON trackable_resource_rule (owner_id, character_class_id, resource_definition_id, unlock_level) WHERE origin = 'CUSTOM' AND character_class_id IS NOT NULL");
        $this->addSql("DROP INDEX uniq_subclass_resource_level");
        $this->addSql("CREATE UNIQUE INDEX uniq_subclass_resource_level_off ON trackable_resource_rule (character_subclass_id, resource_definition_id, unlock_level) WHERE origin = 'OFFICIAL' AND character_subclass_id IS NOT NULL");
        $this->addSql("CREATE UNIQUE INDEX uniq_subclass_resource_level_own ON trackable_resource_rule (owner_id, character_subclass_id, resource_definition_id, unlock_level) WHERE origin = 'CUSTOM' AND character_subclass_id IS NOT NULL");
        $this->addSql("DROP INDEX uniq_race_resource_level");
        $this->addSql("CREATE UNIQUE INDEX uniq_race_resource_level_off ON trackable_resource_rule (character_race_id, resource_definition_id, unlock_level) WHERE origin = 'OFFICIAL' AND character_race_id IS NOT NULL");
        $this->addSql("CREATE UNIQUE INDEX uniq_race_resource_level_own ON trackable_resource_rule (owner_id, character_race_id, resource_definition_id, unlock_level) WHERE origin = 'CUSTOM' AND character_race_id IS NOT NULL");
        $this->addSql("DROP INDEX uniq_feat_resource_level");
        $this->addSql("CREATE UNIQUE INDEX uniq_feat_resource_level_off ON trackable_resource_rule (feat_id, resource_definition_id, unlock_level) WHERE origin = 'OFFICIAL' AND feat_id IS NOT NULL");
        $this->addSql("CREATE UNIQUE INDEX uniq_feat_resource_level_own ON trackable_resource_rule (owner_id, feat_id, resource_definition_id, unlock_level) WHERE origin = 'CUSTOM' AND feat_id IS NOT NULL");
        $this->addSql("DROP INDEX uniq_character_action_class");
        $this->addSql("CREATE UNIQUE INDEX uniq_character_action_class_off ON character_action_class_rule (action_definition_id, character_class_id) WHERE origin = 'OFFICIAL'");
        $this->addSql("CREATE UNIQUE INDEX uniq_character_action_class_own ON character_action_class_rule (owner_id, action_definition_id, character_class_id) WHERE origin = 'CUSTOM'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Owner-scoped assignments may collide under the former global indexes. Restore only through a separately audited migration; no data is deleted.',
        );
    }
}

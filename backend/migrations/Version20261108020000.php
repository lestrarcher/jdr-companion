<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108020000 extends AbstractMigration
{
    public function getDescription(): string { return 'Additive generic feature choices; no catalogue seeds or character changes.'; }
    public function up(Schema $schema): void
    {
        foreach (self::statements() as $sql) $this->addSql($sql);
    }
    /** Shared with isolated PostgreSQL tests; these tables are DBAL-managed like spell acquisitions. */
    public static function statements(): array
    {
        return [
            "CREATE TABLE feature_choice_group (key VARCHAR(100) PRIMARY KEY, name VARCHAR(150) NOT NULL, owner_id INT REFERENCES \"user\"(id) ON DELETE RESTRICT, uniqueness VARCHAR(20) NOT NULL DEFAULT 'group' CHECK (uniqueness IN ('group','character','none')), replacement VARCHAR(20) NOT NULL DEFAULT 'none' CHECK (replacement IN ('none','class-level','grant-level')), enabled BOOLEAN NOT NULL DEFAULT TRUE)",
            "CREATE TABLE feature_choice_option (key VARCHAR(100) PRIMARY KEY, group_key VARCHAR(100) NOT NULL REFERENCES feature_choice_group(key) ON DELETE RESTRICT, feature_id INT NOT NULL REFERENCES character_feature_definition(id) ON DELETE RESTRICT, prerequisites JSONB NOT NULL DEFAULT '{}', enabled BOOLEAN NOT NULL DEFAULT TRUE, UNIQUE(group_key,feature_id), CHECK (jsonb_typeof(prerequisites)='object'))",
            "CREATE TABLE feature_choice_grant (key VARCHAR(100) PRIMARY KEY, group_key VARCHAR(100) NOT NULL REFERENCES feature_choice_group(key) ON DELETE RESTRICT, rule_id INT NOT NULL REFERENCES character_feature_rule(id) ON DELETE RESTRICT, choice_count INT NOT NULL CHECK(choice_count BETWEEN 1 AND 20), enabled BOOLEAN NOT NULL DEFAULT TRUE, UNIQUE(group_key,rule_id))",
            "CREATE TABLE character_feature_choice (key VARCHAR(36) PRIMARY KEY, character_id INT NOT NULL REFERENCES character(id) ON DELETE CASCADE, grant_key VARCHAR(100) NOT NULL REFERENCES feature_choice_grant(key) ON DELETE RESTRICT, option_key VARCHAR(100) NOT NULL REFERENCES feature_choice_option(key) ON DELETE RESTRICT, slot INT NOT NULL CHECK(slot BETWEEN 1 AND 20), acquired_position INT NOT NULL CHECK(acquired_position BETWEEN 1 AND 20), acquired_class_level INT, active BOOLEAN NOT NULL DEFAULT TRUE, replaces_key VARCHAR(36) REFERENCES character_feature_choice(key) ON DELETE RESTRICT, event_key VARCHAR(200), provenance JSONB NOT NULL, actor_id INT REFERENCES \"user\"(id) ON DELETE RESTRICT, reason TEXT, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            "CREATE UNIQUE INDEX uniq_feature_choice_slot ON character_feature_choice(character_id,grant_key,slot) WHERE active",
            "CREATE UNIQUE INDEX uniq_feature_choice_event ON character_feature_choice(character_id,event_key) WHERE event_key IS NOT NULL",
            "CREATE INDEX idx_feature_choice_history ON character_feature_choice(character_id,created_at,key)",
        ];
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Preserve choice history.'); }
}

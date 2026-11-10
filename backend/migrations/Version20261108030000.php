<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108030000 extends AbstractMigration
{
    public function getDescription(): string { return 'Choice replacement windows and preservation of legacy automatic option rules.'; }
    public static function statements(): array
    {
        return [
            "ALTER TABLE feature_choice_group ADD replacement_windows JSONB NOT NULL DEFAULT '[]' CHECK(jsonb_typeof(replacement_windows)='array')",
            "ALTER TABLE feature_choice_group ADD replacement_event_key VARCHAR(100)",
            "ALTER TABLE feature_choice_option ADD source_code VARCHAR(12)",
            "CREATE TABLE feature_choice_rule_gate (rule_id INT PRIMARY KEY REFERENCES character_feature_rule(id) ON DELETE RESTRICT, group_key VARCHAR(100) NOT NULL REFERENCES feature_choice_group(key) ON DELETE RESTRICT)",
            "CREATE TABLE feature_choice_legacy_rule (character_id INT NOT NULL REFERENCES character(id) ON DELETE CASCADE, rule_id INT NOT NULL REFERENCES character_feature_rule(id) ON DELETE RESTRICT, PRIMARY KEY(character_id,rule_id))",
        ];
    }
    public function up(Schema $schema): void { foreach (self::statements() as $sql) $this->addSql($sql); }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Preserve legacy attribution provenance.'); }
}

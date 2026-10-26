<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Nullable casting configuration for feature spell grants; no runtime authorization or data backfill.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feature_spell_grant ADD casting_rules JSONB DEFAULT NULL, ADD resource_definition_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE feature_spell_grant ADD CONSTRAINT fk_feature_spell_grant_resource FOREIGN KEY (resource_definition_id) REFERENCES trackable_resource_definition(id) ON DELETE RESTRICT');
        $this->addSql("ALTER TABLE feature_spell_grant ADD CONSTRAINT chk_feature_spell_casting_rules CHECK (casting_rules IS NULL OR (jsonb_typeof(casting_rules) = 'object' AND jsonb_exists(casting_rules, 'version') AND casting_rules->'version' = '1'::jsonb AND jsonb_exists(casting_rules, 'methods') AND jsonb_typeof(casting_rules->'methods') = 'array' AND casting_rules->'methods' <> '[]'::jsonb))");
        $this->addSql("ALTER TABLE feature_spell_grant ADD CONSTRAINT chk_feature_spell_resource_method CHECK ((resource_definition_id IS NOT NULL) = COALESCE(casting_rules->'methods' @> '[{\"kind\":\"resource\"}]'::jsonb, false))");
        $this->addSql('CREATE INDEX idx_feature_spell_grant_resource ON feature_spell_grant (resource_definition_id)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feature_spell_grant DROP CONSTRAINT chk_feature_spell_resource_method, DROP CONSTRAINT chk_feature_spell_casting_rules, DROP CONSTRAINT fk_feature_spell_grant_resource');
        $this->addSql('DROP INDEX idx_feature_spell_grant_resource');
        $this->addSql('ALTER TABLE feature_spell_grant DROP casting_rules, DROP resource_definition_id');
    }
}

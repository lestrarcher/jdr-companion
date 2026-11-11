<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Allow proficiency grants sourced from selected character features'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE proficiency_grant_rule ADD character_feature_definition_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_proficiency_grant_feature ON proficiency_grant_rule (character_feature_definition_id)');
        $this->addSql('ALTER TABLE proficiency_grant_rule ADD CONSTRAINT fk_proficiency_grant_feature FOREIGN KEY (character_feature_definition_id) REFERENCES character_feature_definition (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE proficiency_grant_rule DROP CONSTRAINT fk_proficiency_grant_feature');
        $this->addSql('DROP INDEX idx_proficiency_grant_feature');
        $this->addSql('ALTER TABLE proficiency_grant_rule DROP character_feature_definition_id');
    }
}

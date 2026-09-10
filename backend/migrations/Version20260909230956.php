<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260909230956 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE character_feature_rule ADD progression_threshold INT DEFAULT NULL');
        $this->addSql('ALTER TABLE character_feature_rule ADD progression_definition_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE character_feature_rule ADD CONSTRAINT FK_CB96A6D6717CB52 FOREIGN KEY (progression_definition_id) REFERENCES progression_definition (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_CB96A6D6717CB52 ON character_feature_rule (progression_definition_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_progression_feature_threshold ON character_feature_rule (progression_definition_id, feature_definition_id, progression_threshold)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE character_feature_rule DROP CONSTRAINT FK_CB96A6D6717CB52');
        $this->addSql('DROP INDEX IDX_CB96A6D6717CB52');
        $this->addSql('DROP INDEX uniq_progression_feature_threshold');
        $this->addSql('ALTER TABLE character_feature_rule DROP progression_threshold');
        $this->addSql('ALTER TABLE character_feature_rule DROP progression_definition_id');
    }
}

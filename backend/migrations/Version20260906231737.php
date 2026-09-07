<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260906231737 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE character_class_level DROP CONSTRAINT fk_78574789e190b699');
        $this->addSql('DROP INDEX uniq_character_class_level');
        $this->addSql('ALTER TABLE character_class_level ADD acquired_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE character_class_level DROP created_at');
        $this->addSql('ALTER TABLE character_class_level DROP updated_at');
        $this->addSql('ALTER TABLE character_class_level RENAME COLUMN class_level TO position');
        $this->addSql('ALTER TABLE character_class_level ADD CONSTRAINT FK_78574789E190B699 FOREIGN KEY (subclass_id) REFERENCES character_subclass (id) NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX uniq_character_level_position ON character_class_level (character_id, position)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE character_class_level DROP CONSTRAINT FK_78574789E190B699');
        $this->addSql('DROP INDEX uniq_character_level_position');
        $this->addSql('ALTER TABLE character_class_level ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE character_class_level RENAME COLUMN position TO class_level');
        $this->addSql('ALTER TABLE character_class_level RENAME COLUMN acquired_at TO created_at');
        $this->addSql('ALTER TABLE character_class_level ADD CONSTRAINT fk_78574789e190b699 FOREIGN KEY (subclass_id) REFERENCES character_subclass (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX uniq_character_class_level ON character_class_level (character_id, character_class_id)');
    }
}

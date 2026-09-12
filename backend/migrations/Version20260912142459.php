<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260912142459 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE campaign_figure ADD character_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE campaign_figure ADD CONSTRAINT FK_F214CAD71136BE75 FOREIGN KEY (character_id) REFERENCES character (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_F214CAD71136BE75 ON campaign_figure (character_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE campaign_figure DROP CONSTRAINT FK_F214CAD71136BE75');
        $this->addSql('DROP INDEX IDX_F214CAD71136BE75');
        $this->addSql('ALTER TABLE campaign_figure DROP character_id');
    }
}

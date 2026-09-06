<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260906005251 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE media ADD usage VARCHAR(20) DEFAULT \'scene\' NOT NULL');
        $this->addSql(
            "UPDATE media
            SET usage = 'portrait'
            WHERE id IN (
                SELECT portrait_id
                FROM campaign_figure
                WHERE portrait_id IS NOT NULL
            )"
        );
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE media DROP usage');
    }
}

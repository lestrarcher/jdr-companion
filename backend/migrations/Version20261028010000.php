<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Optional progression casting ability; no character data backfill.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE progression_definition ADD default_casting_ability VARCHAR(255) DEFAULT NULL');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE progression_definition DROP default_casting_ability');
    }
}

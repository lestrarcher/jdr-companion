<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120180000 extends AbstractMigration
{
    public function getDescription(): string { return 'Illustration facultative des bêtes du référentiel'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE beast ADD image_filename VARCHAR(255) DEFAULT NULL');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE beast DROP COLUMN image_filename');
    }
}

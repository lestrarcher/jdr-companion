<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Campaign-level automatic availability of official items by rarity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE campaign ADD allowed_item_rarities JSON NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign DROP allowed_item_rarities');
    }
}

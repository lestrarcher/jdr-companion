<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bilingual name, copper price and weight to item definitions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magic_item ADD english_name VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE magic_item ADD price_copper INT DEFAULT NULL');
        $this->addSql('ALTER TABLE magic_item ADD weight_grams INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magic_item DROP english_name');
        $this->addSql('ALTER TABLE magic_item DROP price_copper');
        $this->addSql('ALTER TABLE magic_item DROP weight_grams');
    }
}

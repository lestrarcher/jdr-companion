<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le statut sélectionnable aux races existantes sans modifier leurs identités ni leurs relations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_race ADD selectable BOOLEAN DEFAULT TRUE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_race DROP selectable');
    }
}

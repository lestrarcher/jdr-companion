<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les tailles et vitesses raciales structurées sans renseigner les races existantes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_race ADD size_options JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE character_race ADD walking_speed DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE character_race ADD movement_speeds JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_race DROP size_options');
        $this->addSql('ALTER TABLE character_race DROP walking_speed');
        $this->addSql('ALTER TABLE character_race DROP movement_speeds');
    }
}

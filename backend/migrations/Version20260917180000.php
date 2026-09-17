<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les langues, sens, résistances et immunités raciales sans renseigner les races existantes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_race ADD languages JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE character_race ADD language_choice_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE character_race ADD senses JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE character_race ADD damage_resistances JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE character_race ADD damage_immunities JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE character_race ADD condition_immunities JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_race DROP languages');
        $this->addSql('ALTER TABLE character_race DROP language_choice_count');
        $this->addSql('ALTER TABLE character_race DROP senses');
        $this->addSql('ALTER TABLE character_race DROP damage_resistances');
        $this->addSql('ALTER TABLE character_race DROP damage_immunities');
        $this->addSql('ALTER TABLE character_race DROP condition_immunities');
    }
}

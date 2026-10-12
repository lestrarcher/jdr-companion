<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011191340 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add proficiency acquisition mode, defaulting existing rules to ANY.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE proficiency_grant_rule ADD acquisition_mode VARCHAR(20) DEFAULT \'ANY\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE proficiency_grant_rule DROP acquisition_mode');
    }
}

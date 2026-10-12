<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011201312 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a nullable unique import key to proficiency grant rules without claiming existing rules.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE proficiency_grant_rule ADD import_key VARCHAR(120) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_proficiency_grant_rule_import_key ON proficiency_grant_rule (import_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_proficiency_grant_rule_import_key');
        $this->addSql('ALTER TABLE proficiency_grant_rule DROP import_key');
    }
}

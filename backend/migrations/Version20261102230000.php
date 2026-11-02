<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102230000 extends AbstractMigration
{
    public function getDescription(): string { return 'Casting levels and variable charge costs for magic item spell grants'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magic_item_spell_grant ADD casting_level INT DEFAULT NULL');
        $this->addSql('ALTER TABLE magic_item_spell_grant ADD max_casting_level INT DEFAULT NULL');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magic_item_spell_grant DROP casting_level');
        $this->addSql('ALTER TABLE magic_item_spell_grant DROP max_casting_level');
    }
}

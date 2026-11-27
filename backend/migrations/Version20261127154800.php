<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261127154800 extends AbstractMigration
{
    public function getDescription(): string { return 'Apply equipped movement effects automatically'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_magic_item DROP movement_active');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_magic_item ADD movement_active BOOLEAN DEFAULT FALSE NOT NULL');
    }
}

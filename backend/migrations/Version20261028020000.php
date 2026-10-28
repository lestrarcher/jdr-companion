<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028020000 extends AbstractMigration
{
    public function getDescription(): string { return 'Opt-in irreversible progressions and character-wide proven maximum; no backfill.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE progression_definition ADD irreversible BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql("ALTER TABLE character_progression ADD maximum_reached INT DEFAULT NULL, ADD maximum_corrections JSONB DEFAULT '[]' NOT NULL");
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_progression DROP maximum_reached, DROP maximum_corrections');
        $this->addSql('ALTER TABLE progression_definition DROP irreversible');
    }
}

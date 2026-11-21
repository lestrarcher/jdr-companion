<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Explicit beast origin; existing custom beast ID 104 confirmed by the catalogue owner.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE beast ADD origin VARCHAR(16) DEFAULT 'OFFICIAL' NOT NULL");
        // Explicit classification supplied by the owner, not inferred from names or slugs.
        $this->addSql("UPDATE beast SET origin = 'CUSTOM' WHERE id = 104");
    }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE beast DROP origin'); }
}

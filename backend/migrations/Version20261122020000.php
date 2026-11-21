<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122020000 extends AbstractMigration
{
    public function getDescription(): string { return 'Require an owner for CUSTOM beasts; attach existing custom beasts to user 1 as confirmed.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE beast ADD owner_id INT DEFAULT NULL');
        $this->addSql("UPDATE beast SET owner_id = 1 WHERE origin = 'CUSTOM'");
        $this->addSql('ALTER TABLE beast ADD CONSTRAINT fk_beast_owner FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_beast_owner ON beast (owner_id)');
        $this->addSql("ALTER TABLE beast ADD CONSTRAINT chk_beast_origin_owner CHECK ((origin = 'OFFICIAL' AND owner_id IS NULL) OR (origin = 'CUSTOM' AND owner_id IS NOT NULL))");
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing ownership would expose private beast assignments.');
    }
}

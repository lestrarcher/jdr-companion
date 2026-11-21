<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Protéger les bêtes administrées contre les réimports, sans modifier les données existantes'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE beast ADD customized BOOLEAN NOT NULL DEFAULT FALSE'); }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Le marqueur protège les personnalisations du MJ.'); }
}

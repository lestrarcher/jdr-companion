<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261119010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Physical weapon base selected on each magic-item inventory instance'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE character_magic_item ADD base_weapon_key VARCHAR(40) DEFAULT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE character_magic_item DROP base_weapon_key'); }
}

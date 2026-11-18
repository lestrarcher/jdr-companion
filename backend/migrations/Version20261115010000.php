<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261115010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Optional structured weapon profile on the existing item catalogue.'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE magic_item ADD weapon_profile JSON DEFAULT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE magic_item DROP weapon_profile'); }
}

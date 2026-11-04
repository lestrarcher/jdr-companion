<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261104020000 extends AbstractMigration
{
    public function getDescription(): string { return 'Character-specific racial spell-grant casting ability choices'; }
    public function up(Schema $schema): void { $this->addSql("CREATE TABLE character_spell_grant_ability_choice (character_id INT NOT NULL REFERENCES character(id) ON DELETE CASCADE, grant_id INT NOT NULL REFERENCES feature_spell_grant(id) ON DELETE CASCADE, ability VARCHAR(20) NOT NULL CHECK (ability IN ('intelligence','wisdom','charisma')), PRIMARY KEY (character_id,grant_id))"); }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE character_spell_grant_ability_choice'); }
}

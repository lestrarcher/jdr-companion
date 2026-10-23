<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022231500 extends AbstractMigration
{
    public function getDescription(): string { return 'Caractéristique d’incantation des sorts octroyés par les capacités.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE feature_spell_grant ADD casting_ability VARCHAR(10) DEFAULT NULL");
        $this->addSql("ALTER TABLE feature_spell_grant ADD CONSTRAINT chk_feature_spell_grant_ability CHECK (casting_ability IS NULL OR casting_ability IN ('INT','WIS','CHA','STR','DEX','CON','CHOICE'))");
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feature_spell_grant DROP CONSTRAINT chk_feature_spell_grant_ability');
        $this->addSql('ALTER TABLE feature_spell_grant DROP casting_ability');
    }
}

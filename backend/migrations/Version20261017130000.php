<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017130000 extends AbstractMigration
{
    public function getDescription(): string { return 'Provenance et confirmation des nouvelles copies de grimoire, sans reprise de données.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_spell_acquisition ADD copy_details JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE character_spell_acquisition DROP CONSTRAINT chk_spell_acquisition_provenance');
        $this->addSql("ALTER TABLE character_spell_acquisition ADD CONSTRAINT chk_spell_acquisition_provenance CHECK (provenance IN ('level-history','manual-reconciliation','spellbook-copy'))");
        $this->addSql("ALTER TABLE character_spell_acquisition ADD CONSTRAINT chk_spell_acquisition_copy CHECK ((provenance = 'spellbook-copy' AND quota_bucket = 'spellbook' AND replaces_id IS NULL AND copy_details IS NOT NULL AND json_typeof(copy_details) = 'object') OR (provenance <> 'spellbook-copy' AND copy_details IS NULL))");
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Conserver les copies et leur historique.'); }
}

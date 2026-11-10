<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Explicit Book of Shadows owner and acquired pact; no inventory backfill.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_magic_item ADD pact_owner_id INT DEFAULT NULL, ADD pact_choice_key VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_pact_tome_owner ON character_magic_item (pact_owner_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_pact_tome_choice ON character_magic_item (pact_choice_key)');
        $this->addSql('ALTER TABLE character_magic_item ADD CONSTRAINT fk_pact_tome_owner FOREIGN KEY (pact_owner_id) REFERENCES character (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE character_magic_item ADD CONSTRAINT fk_pact_tome_choice FOREIGN KEY (pact_choice_key) REFERENCES character_feature_choice (key) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE character_magic_item ADD CONSTRAINT chk_pact_tome_binding CHECK ((pact_owner_id IS NULL AND pact_choice_key IS NULL) OR (pact_owner_id IS NOT NULL AND pact_choice_key IS NOT NULL AND quantity = 1))');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Preserve acquired pact and inventory provenance.'); }
}

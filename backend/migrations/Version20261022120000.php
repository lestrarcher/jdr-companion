<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Journal des qualifications de sorts, sans réécriture des décisions existantes.'; }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE character_spell_qualification (
                acquisition_id INT PRIMARY KEY REFERENCES character_spell_acquisition(id) ON DELETE RESTRICT,
                rule_id INT NOT NULL REFERENCES spell_policy_choice_rule(id) ON DELETE RESTRICT,
                decided_by_id INT NOT NULL REFERENCES "user"(id) ON DELETE RESTRICT,
                reason TEXT NOT NULL,
                decided_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT chk_spell_qualification_reason CHECK (length(trim(reason)) BETWEEN 1 AND 10000)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_spell_qualification_rule ON character_spell_qualification(rule_id)');
        $this->addSql('CREATE INDEX idx_spell_qualification_actor ON character_spell_qualification(decided_by_id)');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Préserver le journal des qualifications.'); }
}

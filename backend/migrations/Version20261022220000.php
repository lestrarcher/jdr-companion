<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022220000 extends AbstractMigration
{
    public function getDescription(): string { return 'Définitions de sorts octroyés par les capacités (sans acquisition automatique).'; }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE feature_spell_grant (
    id SERIAL PRIMARY KEY,
    feature_id INT NOT NULL REFERENCES character_feature_definition(id) ON DELETE RESTRICT,
    spell_id INT NULL REFERENCES spell_definition(id) ON DELETE RESTRICT,
    mode VARCHAR(10) NOT NULL,
    spell_level SMALLINT NULL,
    class_id INT NULL REFERENCES character_class(id) ON DELETE RESTRICT,
    schools JSONB NULL,
    unlock_level SMALLINT NOT NULL DEFAULT 1,
    CONSTRAINT chk_feature_spell_grant_mode CHECK (mode IN ('fixed','choice')),
    CONSTRAINT chk_feature_spell_grant_level CHECK (unlock_level BETWEEN 1 AND 20),
    CONSTRAINT chk_feature_spell_grant_spell_level CHECK (spell_level IS NULL OR spell_level BETWEEN 0 AND 9),
    CONSTRAINT chk_feature_spell_grant_target CHECK ((mode='fixed' AND spell_id IS NOT NULL AND spell_level IS NULL AND class_id IS NULL AND schools IS NULL) OR (mode='choice' AND spell_id IS NULL AND spell_level IS NOT NULL))
)
SQL);
        $this->addSql('CREATE INDEX idx_feature_spell_grant_feature ON feature_spell_grant(feature_id)');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE feature_spell_grant'); }
}

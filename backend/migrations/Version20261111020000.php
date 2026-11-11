<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Include character features among the allowed proficiency grant rule sources';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE proficiency_grant_rule DROP CONSTRAINT chk_proficiency_rule_one_source');
        $this->addSql('ALTER TABLE proficiency_grant_rule ADD CONSTRAINT chk_proficiency_rule_one_source CHECK (num_nonnulls(character_race_id, character_class_id, character_subclass_id, feat_id, character_background_id, character_feature_definition_id) = 1)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT count(*) FROM proficiency_grant_rule WHERE character_feature_definition_id IS NOT NULL') > 0, 'Cannot restore the old constraint while feature proficiency grants exist.');
        $this->addSql('ALTER TABLE proficiency_grant_rule DROP CONSTRAINT chk_proficiency_rule_one_source');
        $this->addSql('ALTER TABLE proficiency_grant_rule ADD CONSTRAINT chk_proficiency_rule_one_source CHECK (num_nonnulls(character_race_id, character_class_id, character_subclass_id, feat_id, character_background_id) = 1)');
    }
}

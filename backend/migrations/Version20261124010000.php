<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add structured feature ability score effects; no character or catalogue updates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_feature_definition ADD ability_score_effects JSON DEFAULT NULL');
        $this->addSql("ALTER TABLE character_feature_definition ADD CONSTRAINT chk_feature_ability_score_effects CHECK (ability_score_effects IS NULL OR json_typeof(ability_score_effects) = 'array')");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve configured ability score effects.');
    }
}

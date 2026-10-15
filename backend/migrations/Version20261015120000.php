<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Champs descriptifs optionnels et propositions narratives des historiques.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_background ADD equipment TEXT DEFAULT NULL, ADD feature_name VARCHAR(120) DEFAULT NULL, ADD feature_description TEXT DEFAULT NULL');
        foreach (['personality_traits', 'ideals', 'bonds', 'flaws'] as $column) {
            $this->addSql("ALTER TABLE character_background ADD $column JSON DEFAULT '[]' NOT NULL");
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Préserver les informations narratives enregistrées.');
    }
}

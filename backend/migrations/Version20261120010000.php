<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Associer des définitions de capacités réutilisables aux bêtes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE beast_feature (beast_id INT NOT NULL, feature_definition_id INT NOT NULL, display_order INT DEFAULT 0 NOT NULL, PRIMARY KEY (beast_id, feature_definition_id))');
        $this->addSql('CREATE INDEX idx_beast_feature_definition ON beast_feature (feature_definition_id)');
        $this->addSql('ALTER TABLE beast_feature ADD CONSTRAINT fk_beast_feature_beast FOREIGN KEY (beast_id) REFERENCES beast (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE beast_feature ADD CONSTRAINT fk_beast_feature_definition FOREIGN KEY (feature_definition_id) REFERENCES character_feature_definition (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE beast_feature');
    }
}

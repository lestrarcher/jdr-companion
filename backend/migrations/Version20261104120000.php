<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Assign reference features to multiple characters independently'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE character_feature_assignment (id SERIAL NOT NULL, character_id INT NOT NULL, feature_definition_id INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_character_feature_assignment ON character_feature_assignment (character_id, feature_definition_id)');
        $this->addSql('CREATE INDEX idx_character_feature_assignment_character ON character_feature_assignment (character_id)');
        $this->addSql('CREATE INDEX idx_character_feature_assignment_feature ON character_feature_assignment (feature_definition_id)');
        $this->addSql('ALTER TABLE character_feature_assignment ADD CONSTRAINT fk_feature_assignment_character FOREIGN KEY (character_id) REFERENCES character (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE character_feature_assignment ADD CONSTRAINT fk_feature_assignment_definition FOREIGN KEY (feature_definition_id) REFERENCES character_feature_definition (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE character_feature_assignment'); }
}

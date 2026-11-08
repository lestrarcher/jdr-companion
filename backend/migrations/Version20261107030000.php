<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107030000 extends AbstractMigration
{
    public function getDescription(): string { return 'Structure only: optional declarative armor class effects; no catalogue configuration.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magic_item ADD armor_class_effects JSON DEFAULT NULL');
        $this->addSql("ALTER TABLE magic_item ADD CONSTRAINT chk_item_ac_effects CHECK (armor_class_effects IS NULL OR json_typeof(armor_class_effects) = 'array')");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve manually configured AC effects.');
    }
}

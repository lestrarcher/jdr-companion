<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional physical armor profiles without rewriting catalogue or possessions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magic_item ADD armor_type VARCHAR(12) DEFAULT NULL, ADD base_armor_class INT DEFAULT NULL');
        $this->addSql("ALTER TABLE magic_item ADD CONSTRAINT chk_magic_item_armor_profile CHECK ((armor_type IS NULL AND base_armor_class IS NULL) OR (armor_type IS NOT NULL AND base_armor_class IS NOT NULL AND base_armor_class BETWEEN 1 AND 30 AND ((category = 'armor' AND armor_type IN ('light', 'medium', 'heavy')) OR (category = 'shield' AND armor_type = 'shield' AND base_armor_class = 2))))");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Configured armor profiles must be preserved.');
    }
}

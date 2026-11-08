<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107020000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add optional armor strength and stealth metadata; no possession changes.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magic_item ADD armor_strength_requirement INT DEFAULT NULL, ADD armor_stealth_disadvantage BOOLEAN DEFAULT NULL');
        $this->addSql("ALTER TABLE magic_item ADD CONSTRAINT chk_armor_strength CHECK (armor_strength_requirement IS NULL OR (armor_type IS NOT NULL AND armor_type = 'heavy' AND armor_strength_requirement BETWEEN 1 AND 30)), ADD CONSTRAINT chk_armor_stealth CHECK (armor_stealth_disadvantage IS NULL OR (armor_type IS NOT NULL AND (armor_type <> 'shield' OR armor_stealth_disadvantage = false)))");
    }

    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Preserve configured armor metadata.'); }
}

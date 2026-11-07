<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow character-owned ordinary items without a catalogue definition.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_magic_item ALTER magic_item_id DROP NOT NULL');
        $this->addSql('ALTER TABLE character_magic_item ADD custom_description TEXT DEFAULT NULL');
        $this->addSql("ALTER TABLE character_magic_item ADD CONSTRAINT chk_custom_item CHECK (magic_item_id IS NOT NULL OR (character_id IS NOT NULL AND display_name IS NOT NULL AND LENGTH(TRIM(display_name)) > 0 AND quantity BETWEEN 1 AND 9999 AND equipped = false AND attuned = false AND current_charges IS NULL AND inscribed_spell_id IS NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Personal possessions must be preserved.');
    }
}

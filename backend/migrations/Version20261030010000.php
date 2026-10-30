<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Additive inventory foundation. No catalogue import or possession rewrite. */
final class Version20261030010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Inventory foundation: preserve magic item IDs and state, add catalogue metadata and legacy equipment markers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE magic_item ADD origin VARCHAR(8) DEFAULT 'CUSTOM' NOT NULL, ADD owner_id INT DEFAULT NULL, ADD category VARCHAR(24) DEFAULT 'other' NOT NULL, ADD magical BOOLEAN DEFAULT true NOT NULL, ADD equippable BOOLEAN DEFAULT true NOT NULL, ADD quantity_mode VARCHAR(16) DEFAULT 'legacy' NOT NULL, ADD equipment_slot VARCHAR(16) DEFAULT 'free' NOT NULL, ADD hand_usage VARCHAR(16) DEFAULT NULL, ADD edition VARCHAR(4) DEFAULT NULL, ADD source_reference VARCHAR(255) DEFAULT NULL, ADD import_key VARCHAR(150) DEFAULT NULL, ADD archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL");
        $this->addSql('UPDATE magic_item AS item SET owner_id = campaign.owner_id FROM campaign WHERE campaign.id = item.campaign_id');
        $this->addSql('ALTER TABLE magic_item ALTER owner_id SET NOT NULL');
        $this->addSql('CREATE INDEX idx_magic_item_owner ON magic_item (owner_id)');
        $this->addSql('ALTER TABLE magic_item ADD CONSTRAINT fk_magic_item_owner FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE character_magic_item ADD equipped_slot VARCHAR(16) DEFAULT NULL, ADD hands_used INT DEFAULT NULL, ADD legacy_stack BOOLEAN DEFAULT false NOT NULL');
        // Every legacy definition is still equippable. Preserve all old stacks without splitting them.
        $this->addSql('UPDATE character_magic_item SET legacy_stack = true WHERE quantity > 1');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Inventory metadata must not be discarded automatically. Restore only through a reviewed data-preserving migration.');
    }
}

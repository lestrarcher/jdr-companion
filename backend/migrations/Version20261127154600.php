<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127154600 extends AbstractMigration
{
    public function getDescription(): string { return 'Structured movement effects for SRD magic items'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magic_item ADD movement_effects JSON DEFAULT NULL');
        $this->addSql("UPDATE magic_item SET movement_effects = ?, equippable = TRUE, quantity_mode = 'individual' WHERE import_key = ? AND equipment_slot = 'free'", ['[{"mode":"fly","operation":"minimum","value":18,"activation":"action","durationMinutes":60,"recharge":"1d12 hours"}]', 'srd-5.1:magic-item:wings-of-flying']);
        $this->addSql("UPDATE magic_item SET movement_effects = ?, equippable = TRUE, quantity_mode = 'individual' WHERE import_key = ? AND equipment_slot = 'free'", ['[{"mode":"fly","operation":"walking","value":null,"activation":"limited","durationMinutes":240}]', 'srd-5.1:magic-item:winged-boots']);
        $this->addSql("UPDATE magic_item SET movement_effects = ?, equippable = TRUE, quantity_mode = 'individual' WHERE import_key = ? AND equipment_slot = 'free'", ['[{"mode":"swim","operation":"minimum","value":12,"activation":"passive"}]', 'srd-5.1:magic-item:ring-of-swimming']);
        $this->addSql("UPDATE magic_item SET movement_effects = ?, equippable = TRUE, quantity_mode = 'individual' WHERE import_key = ? AND equipment_slot = 'free'", ['[{"mode":"swim","operation":"minimum","value":18,"activation":"action"}]', 'srd-5.1:magic-item:cloak-of-the-manta-ray']);
        $this->addSql("UPDATE magic_item SET movement_effects = ?, equippable = TRUE, quantity_mode = 'individual' WHERE import_key = ? AND equipment_slot = 'free'", ['[{"mode":"walk","operation":"multiply","value":2,"activation":"bonus-action","durationMinutes":10}]', 'srd-5.1:magic-item:boots-of-speed']);
        $this->addSql("UPDATE magic_item SET movement_effects = ?, equippable = TRUE, quantity_mode = 'individual' WHERE import_key = ? AND equipment_slot = 'free'", ['[{"mode":"walk","operation":"minimum","value":9,"activation":"passive","ignoreArmorPenalty":true}]', 'srd-5.1:magic-item:boots-of-striding-and-springing']);
        $this->addSql("UPDATE magic_item SET movement_effects = ?, equippable = TRUE, quantity_mode = 'individual' WHERE import_key = ? AND equipment_slot = 'free'", ['[{"mode":"climb","operation":"walking","value":null,"activation":"passive"}]', 'srd-5.1:magic-item:slippers-of-spider-climbing']);
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Equipment classification may have been used since migration.');
    }
}

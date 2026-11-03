<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102150000 extends AbstractMigration
{
    public function getDescription(): string { return 'Keep spellbooks as unique campaign items when unowned'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_magic_item ADD campaign_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE character_magic_item ADD display_name VARCHAR(200) DEFAULT NULL');
        $this->addSql('UPDATE character_magic_item i SET campaign_id = c.campaign_id FROM character c WHERE i.character_id = c.id');
        $this->addSql('ALTER TABLE character_magic_item ALTER campaign_id SET NOT NULL');
        $this->addSql("UPDATE character_magic_item i SET display_name = 'Grimoire de ' || c.name FROM character c JOIN magic_item m ON m.category = 'spellbook' WHERE i.character_id = c.id AND i.magic_item_id = m.id AND i.display_name IS NULL");
        $this->addSql('CREATE INDEX IDX_CMI_CAMPAIGN_RESERVE ON character_magic_item (campaign_id, character_id)');
        $this->addSql('ALTER TABLE character_magic_item ADD CONSTRAINT FK_CMI_CAMPAIGN_RESERVE FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE CASCADE');
        // Preserve existing items when a character is deleted: the database FK must allow NULL.
        $this->addSql('ALTER TABLE character_magic_item DROP CONSTRAINT IF EXISTS fk_9e3d4f26a4d60f7');
        $this->addSql("DO $$ DECLARE fk text; BEGIN FOR fk IN SELECT c.conname FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY(c.conkey) WHERE c.conrelid = 'character_magic_item'::regclass AND c.contype = 'f' AND a.attname = 'character_id' LOOP EXECUTE format('ALTER TABLE character_magic_item DROP CONSTRAINT %I', fk); END LOOP; END $$");
        $this->addSql('ALTER TABLE character_magic_item ALTER character_id DROP NOT NULL');
        $this->addSql('ALTER TABLE character_magic_item ADD CONSTRAINT FK_CMI_CHARACTER_RESERVE FOREIGN KEY (character_id) REFERENCES character (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM character_magic_item WHERE character_id IS NULL");
        $this->addSql('ALTER TABLE character_magic_item DROP CONSTRAINT FK_CMI_CHARACTER_RESERVE');
        $this->addSql('ALTER TABLE character_magic_item ALTER character_id SET NOT NULL');
        $this->addSql('ALTER TABLE character_magic_item ADD CONSTRAINT FK_CMI_CHARACTER_RESTORE FOREIGN KEY (character_id) REFERENCES character (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE character_magic_item DROP CONSTRAINT FK_CMI_CAMPAIGN_RESERVE');
        $this->addSql('DROP INDEX IDX_CMI_CAMPAIGN_RESERVE');
        $this->addSql('ALTER TABLE character_magic_item DROP COLUMN campaign_id');
        $this->addSql('ALTER TABLE character_magic_item DROP COLUMN display_name');
    }
}

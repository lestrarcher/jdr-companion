<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101160000 extends AbstractMigration
{
    public function getDescription(): string { return 'Associate a chosen spell with an owned magic scroll'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_magic_item ADD inscribed_spell_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_CHARACTER_MAGIC_ITEM_INSCRIBED_SPELL ON character_magic_item (inscribed_spell_id)');
        $this->addSql('ALTER TABLE character_magic_item ADD CONSTRAINT FK_CHARACTER_MAGIC_ITEM_INSCRIBED_SPELL FOREIGN KEY (inscribed_spell_id) REFERENCES spell_definition (id) ON DELETE RESTRICT');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_magic_item DROP CONSTRAINT FK_CHARACTER_MAGIC_ITEM_INSCRIBED_SPELL');
        $this->addSql('DROP INDEX IDX_CHARACTER_MAGIC_ITEM_INSCRIBED_SPELL');
        $this->addSql('ALTER TABLE character_magic_item DROP inscribed_spell_id');
    }
}

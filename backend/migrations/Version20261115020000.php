<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261115020000 extends AbstractMigration
{
    public function getDescription(): string { return 'End infusion-only attunement when the active infusion ends, including lost choices.'; }
    public static function guards(): array
    {
        return [
            <<<'SQL'
CREATE FUNCTION clear_ended_infusion_attunement() RETURNS trigger AS $$
BEGIN
    IF OLD.active AND NOT NEW.active AND OLD.item_id IS NOT NULL AND NEW.end_reason <> 'item_removed' THEN
        UPDATE character_magic_item owned SET attuned=FALSE
        FROM magic_item item
        WHERE owned.id=OLD.item_id AND owned.attuned AND item.id=owned.magic_item_id AND NOT item.requires_attunement AND NOT item.magical;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL,
            'CREATE TRIGGER clear_ended_infusion_attunement AFTER UPDATE OF active ON character_item_infusion FOR EACH ROW EXECUTE FUNCTION clear_ended_infusion_attunement()',
        ];
    }
    public function up(Schema $schema): void { foreach (self::guards() as $sql) $this->addSql($sql); }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER clear_ended_infusion_attunement ON character_item_infusion');
        $this->addSql('DROP FUNCTION clear_ended_infusion_attunement()');
    }
}

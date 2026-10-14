<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Relie Bricolages magiques à une ressource INT minimum 1 sans recharge au repos.'; }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DO $$
DECLARE resource_id INTEGER;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM character_feature_definition WHERE slug = 'magical-tinkering' AND origin = 'OFFICIAL' AND owner_id IS NULL AND custom = false) THEN
        RAISE EXCEPTION 'Bricolages : capacité officielle absente ou personnalisée';
    END IF;
    INSERT INTO trackable_resource_definition
        (slug, name, recharge_type, maximum_type, base_maximum, multiplier, minimum_maximum, scaling_ability, custom, created_at, updated_at, origin, owner_id)
        VALUES ('magical-tinkering', 'Bricolages magiques', 'none', 'ability-modifier', 0, 1, 1, 'intelligence', false, NOW(), NOW(), 'OFFICIAL', NULL)
        ON CONFLICT (slug) DO NOTHING;
    SELECT id INTO resource_id FROM trackable_resource_definition WHERE slug = 'magical-tinkering'
        AND origin = 'OFFICIAL' AND owner_id IS NULL AND custom = false
        AND recharge_type = 'none' AND maximum_type = 'ability-modifier'
        AND base_maximum = 0 AND multiplier = 1 AND minimum_maximum = 1 AND scaling_ability = 'intelligence';
    IF resource_id IS NULL THEN RAISE EXCEPTION 'Bricolages : ressource existante incompatible, aucune donnée écrasée'; END IF;
    IF EXISTS (SELECT 1 FROM character_feature_definition WHERE slug = 'magical-tinkering' AND resource_definition_id IS NOT NULL AND resource_definition_id <> resource_id) THEN
        RAISE EXCEPTION 'Bricolages : liaison existante incompatible';
    END IF;
    UPDATE character_feature_definition SET resource_definition_id = resource_id WHERE slug = 'magical-tinkering' AND resource_definition_id IS NULL;
END $$
SQL);
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Conserver les ressources et leurs valeurs historiques.'); }
}

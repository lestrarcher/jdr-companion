<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le maximum de deux utilisations de Fougue au niveau 17 de guerrier.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DO $$
DECLARE
    resource_id INTEGER;
    fighter_id INTEGER;
    feature_id INTEGER;
BEGIN
    SELECT id INTO resource_id FROM trackable_resource_definition WHERE slug = 'utilisation-de-fougue';
    SELECT id INTO fighter_id FROM character_class WHERE slug = 'fighter';
    SELECT id INTO feature_id FROM character_feature_definition WHERE slug = 'fougue';

    IF resource_id IS NULL OR fighter_id IS NULL OR feature_id IS NULL THEN
        RAISE EXCEPTION 'Fougue tier: missing resource, fighter or feature';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM trackable_resource_definition WHERE id = resource_id
        AND maximum_type = 'fixed' AND base_maximum = 1 AND minimum_maximum = 1
        AND multiplier = 1 AND scaling_ability IS NULL AND recharge_type = 'short-rest') THEN
        RAISE EXCEPTION 'Fougue tier: unexpected maximum or recharge configuration';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM character_feature_definition WHERE id = feature_id
        AND resource_definition_id = resource_id) THEN
        RAISE EXCEPTION 'Fougue tier: unexpected feature resource link';
    END IF;
    IF (SELECT count(*) FROM character_feature_rule WHERE feature_definition_id = feature_id) <> 2
        OR EXISTS (SELECT 1 FROM character_feature_rule WHERE feature_definition_id = feature_id
            AND (character_class_id IS DISTINCT FROM fighter_id OR unlock_level NOT IN (2, 17)
                OR character_subclass_id IS NOT NULL OR character_race_id IS NOT NULL
                OR feat_id IS NOT NULL OR progression_definition_id IS NOT NULL
                OR progression_threshold IS NOT NULL))
        OR NOT EXISTS (SELECT 1 FROM character_feature_rule WHERE feature_definition_id = feature_id AND unlock_level = 2)
        OR NOT EXISTS (SELECT 1 FROM character_feature_rule WHERE feature_definition_id = feature_id AND unlock_level = 17) THEN
        RAISE EXCEPTION 'Fougue tier: expected fighter feature assignments at levels 2 and 17';
    END IF;
    -- Refuse to hide a competing origin, bonus or tier. Accept an already-correct rule.
    IF EXISTS (SELECT 1 FROM trackable_resource_rule WHERE resource_definition_id = resource_id
        AND (character_class_id IS DISTINCT FROM fighter_id OR unlock_level <> 17
            OR maximum_override IS DISTINCT FROM 2 OR maximum_bonus <> 0
            OR character_subclass_id IS NOT NULL OR character_race_id IS NOT NULL OR feat_id IS NOT NULL)) THEN
        RAISE EXCEPTION 'Fougue tier: conflicting resource rule';
    END IF;

    INSERT INTO trackable_resource_rule
        (resource_definition_id, character_class_id, character_subclass_id, character_race_id, feat_id,
         unlock_level, maximum_override, maximum_bonus)
    SELECT resource_id, fighter_id, NULL, NULL, NULL, 17, 2, 0
    WHERE NOT EXISTS (SELECT 1 FROM trackable_resource_rule WHERE resource_definition_id = resource_id
        AND character_class_id = fighter_id AND unlock_level = 17);
END $$
SQL);
    }

    public function down(Schema $schema): void
    {
        // An identical rule may predate this migration; deleting it would not be a safe inverse.
        $this->throwIrreversibleMigrationException('La règle de Fougue peut préexister ; son retrait nécessite une décision explicite.');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Consolide Présage sur les capacités canoniques et ajoute le maximum de 3 pour Divination 14.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DO $$
DECLARE
    resource_id INTEGER;
    subclass_id INTEGER;
    portent_id INTEGER;
    greater_id INTEGER;
BEGIN
    SELECT id INTO resource_id FROM trackable_resource_definition WHERE slug = 'des-de-presage';
    SELECT s.id INTO subclass_id FROM character_subclass s
        JOIN character_class c ON c.id = s.character_class_id
        WHERE s.slug = 'divination' AND c.slug = 'wizard';
    SELECT id INTO portent_id FROM character_feature_definition WHERE slug = 'divination-portent';
    SELECT id INTO greater_id FROM character_feature_definition WHERE slug = 'divination-greater-portent';

    IF resource_id IS NULL OR subclass_id IS NULL OR portent_id IS NULL OR greater_id IS NULL THEN
        RAISE EXCEPTION 'Portent consolidation: missing resource, wizard/divination subclass or canonical features';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM trackable_resource_definition WHERE id = resource_id
        AND recharge_type = 'long-rest' AND maximum_type = 'fixed' AND base_maximum = 2 AND minimum_maximum <= 2) THEN
        RAISE EXCEPTION 'Portent consolidation: expected fixed maximum 2 and long-rest recharge';
    END IF;
    IF EXISTS (SELECT 1 FROM character_feature_definition
        WHERE slug IN ('presage', 'presage-superieur', 'divination-portent', 'divination-greater-portent')
        AND resource_definition_id IS NOT NULL AND resource_definition_id <> resource_id) THEN
        RAISE EXCEPTION 'Portent consolidation: conflicting feature resource link';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_constraint WHERE contype = 'f'
        AND confrelid = 'character_feature_definition'::regclass
        AND (conrelid <> 'character_feature_rule'::regclass OR confdeltype <> 'c')) THEN
        RAISE EXCEPTION 'Portent consolidation: unexpected incoming feature foreign key; review before deleting';
    END IF;

    -- Do not silently discard assignments outside the two known Divination tiers.
    IF EXISTS (SELECT 1 FROM character_feature_rule r JOIN character_feature_definition f ON f.id = r.feature_definition_id
        WHERE f.slug IN ('presage', 'presage-superieur', 'divination-portent', 'divination-greater-portent')
        AND (r.character_subclass_id IS DISTINCT FROM subclass_id
            OR r.character_class_id IS NOT NULL OR r.character_race_id IS NOT NULL OR r.feat_id IS NOT NULL
            OR r.progression_definition_id IS NOT NULL OR r.progression_threshold IS NOT NULL
            OR r.unlock_level <> CASE WHEN f.slug IN ('presage', 'divination-portent') THEN 2 ELSE 14 END)) THEN
        RAISE EXCEPTION 'Portent consolidation: unexpected feature assignment';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM character_feature_rule WHERE feature_definition_id = portent_id
        AND character_subclass_id = subclass_id AND unlock_level = 2)
        OR NOT EXISTS (SELECT 1 FROM character_feature_rule WHERE feature_definition_id = greater_id
        AND character_subclass_id = subclass_id AND unlock_level = 14) THEN
        RAISE EXCEPTION 'Portent consolidation: missing canonical Divination assignments';
    END IF;
    IF EXISTS (SELECT 1 FROM trackable_resource_rule WHERE resource_definition_id = resource_id
        AND (character_subclass_id IS DISTINCT FROM subclass_id OR unlock_level <> 14
            OR maximum_override IS DISTINCT FROM 3 OR maximum_bonus <> 0
            OR character_class_id IS NOT NULL OR character_race_id IS NOT NULL OR feat_id IS NOT NULL)) THEN
        RAISE EXCEPTION 'Portent consolidation: conflicting resource rule';
    END IF;

    UPDATE character_feature_definition SET resource_definition_id = resource_id
        WHERE id = portent_id;
    INSERT INTO trackable_resource_rule
        (resource_definition_id, character_subclass_id, character_class_id, character_race_id, feat_id,
         unlock_level, maximum_override, maximum_bonus)
        SELECT resource_id, subclass_id, NULL, NULL, NULL, 14, 3, 0
        WHERE NOT EXISTS (SELECT 1 FROM trackable_resource_rule
            WHERE resource_definition_id = resource_id AND character_subclass_id = subclass_id AND unlock_level = 14);
    DELETE FROM character_feature_rule WHERE feature_definition_id IN
        (SELECT id FROM character_feature_definition WHERE slug IN ('presage', 'presage-superieur'));
    DELETE FROM character_feature_definition WHERE slug IN ('presage', 'presage-superieur');
END $$
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les définitions historiques supprimées ne peuvent pas être reconstruites fidèlement.');
    }
}

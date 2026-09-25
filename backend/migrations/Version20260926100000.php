<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rattache cinq compteurs de domaines de clerc aux capacités canoniques D&D 2014, sans changer leurs paramètres.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DO $$
DECLARE
    item RECORD;
    pool_id INTEGER;
    legacy_id INTEGER;
    canonical_id INTEGER;
    domain_id INTEGER;
BEGIN
    IF EXISTS (SELECT 1 FROM pg_constraint WHERE contype = 'f'
        AND confrelid = 'character_feature_definition'::regclass
        AND conrelid <> 'character_feature_rule'::regclass) THEN
        RAISE EXCEPTION 'Cleric domains: unexpected incoming feature relation';
    END IF;
    FOR item IN SELECT * FROM (VALUES
        ('warding-flare-uses', 'warding-flare', 'light-domain-warding-flare', 'light-domain', 1, 'ability-modifier', 'wisdom'),
        ('eyes-of-the-grave-uses', 'eyes-of-the-grave', 'grave-domain-eyes-of-the-grave', 'grave-domain', 1, 'ability-modifier', 'wisdom'),
        ('sentinel-at-deaths-door-uses', 'sentinel-at-deaths-door', 'grave-domain-sentinel-at-death-s-door', 'grave-domain', 6, 'ability-modifier', 'wisdom'),
        ('wrath-of-the-storm-uses', 'wrath-of-the-storm', 'tempest-domain-wrath-of-the-storm', 'tempest-domain', 1, 'ability-modifier', 'wisdom'),
        ('steps-of-night-uses', 'steps-of-night', 'twilight-domain-steps-of-night', 'twilight-domain', 6, 'proficiency-bonus', NULL)
    ) AS mapping(pool_slug, legacy_slug, canonical_slug, domain_slug, acquisition, maximum_type, ability)
    LOOP
        SELECT id INTO pool_id FROM trackable_resource_definition WHERE slug = item.pool_slug;
        SELECT id INTO legacy_id FROM character_feature_definition WHERE slug = item.legacy_slug;
        SELECT id INTO canonical_id FROM character_feature_definition WHERE slug = item.canonical_slug;
        SELECT s.id INTO domain_id FROM character_subclass s JOIN character_class c ON c.id = s.character_class_id
            WHERE s.slug = item.domain_slug AND c.slug = 'cleric';
        IF pool_id IS NULL OR legacy_id IS NULL OR canonical_id IS NULL OR domain_id IS NULL THEN
            RAISE EXCEPTION 'Cleric domains: missing stable identity for %', item.pool_slug;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM trackable_resource_definition WHERE id = pool_id
            AND maximum_type = item.maximum_type AND scaling_ability IS NOT DISTINCT FROM item.ability
            AND base_maximum = 0 AND multiplier = 1 AND minimum_maximum = 1 AND recharge_type = 'long-rest') THEN
            RAISE EXCEPTION 'Cleric domains: unexpected maximum or recharge for %', item.pool_slug;
        END IF;
        IF EXISTS (SELECT 1 FROM trackable_resource_rule WHERE resource_definition_id = pool_id) THEN
            RAISE EXCEPTION 'Cleric domains: unexpected independent resource rule for %', item.pool_slug;
        END IF;
        IF (SELECT count(*) FROM character_feature_rule WHERE feature_definition_id = canonical_id) <> 1
            OR NOT EXISTS (SELECT 1 FROM character_feature_rule WHERE feature_definition_id = canonical_id
                AND character_subclass_id = domain_id AND unlock_level = item.acquisition
                AND character_class_id IS NULL AND character_race_id IS NULL AND feat_id IS NULL
                AND progression_definition_id IS NULL AND progression_threshold IS NULL) THEN
            RAISE EXCEPTION 'Cleric domains: unexpected canonical assignment for %', item.pool_slug;
        END IF;
        IF EXISTS (SELECT 1 FROM character_feature_rule WHERE feature_definition_id = legacy_id) THEN
            RAISE EXCEPTION 'Cleric domains: legacy feature still assigned for %', item.pool_slug;
        END IF;
        IF EXISTS (SELECT 1 FROM character_feature_definition WHERE resource_definition_id = pool_id
                AND id NOT IN (legacy_id, canonical_id))
            OR EXISTS (SELECT 1 FROM character_feature_definition WHERE id IN (legacy_id, canonical_id)
                AND resource_definition_id IS NOT NULL AND resource_definition_id <> pool_id)
            OR (SELECT count(*) FROM character_feature_definition WHERE id IN (legacy_id, canonical_id)
                AND resource_definition_id = pool_id) <> 1 THEN
            RAISE EXCEPTION 'Cleric domains: ambiguous resource provider for %', item.pool_slug;
        END IF;

        UPDATE character_feature_definition SET resource_definition_id = NULL
            WHERE id = legacy_id AND resource_definition_id IS NOT NULL;
        UPDATE character_feature_definition SET resource_definition_id = pool_id
            WHERE id = canonical_id AND resource_definition_id IS DISTINCT FROM pool_id;
    END LOOP;
END $$
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les liens corrects peuvent préexister ; ne pas réactiver automatiquement les fournisseurs historiques.');
    }
}

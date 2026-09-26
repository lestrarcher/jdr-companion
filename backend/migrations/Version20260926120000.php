<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relie les cinq aptitudes FTD aux trois compteurs existants en conservant les fournisseurs des anciennes races.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DO $$
DECLARE
    ancestry RECORD;
    pool RECORD;
    provider RECORD;
    pool_id INTEGER;
    feature_id INTEGER;
BEGIN
    -- These roots are distinct. The old red/silver variants inherit only dragonborn.
    FOR ancestry IN SELECT * FROM (VALUES
        ('dragonborn', NULL),
        ('chromatic-dragonborn-red', 'dragonborn'),
        ('metallic-dragonborn-silver', 'dragonborn'),
        ('dragonborn-chromatic-ftd', NULL),
        ('dragonborn-gem-ftd', NULL),
        ('dragonborn-metallic-ftd', NULL)
    ) AS races(slug, parent_slug)
    LOOP
        IF NOT EXISTS (SELECT 1 FROM character_race r LEFT JOIN character_race p ON p.id = r.parent_race_id
            WHERE r.slug = ancestry.slug AND p.slug IS NOT DISTINCT FROM ancestry.parent_slug) THEN
            RAISE EXCEPTION 'FTD resources: missing race or unexpected ancestry for %', ancestry.slug;
        END IF;
    END LOOP;

    FOR pool IN SELECT * FROM (VALUES
        ('breath-weapon-uses', 'proficiency-bonus', 0, 1,
            ARRAY['breath-weapon', 'racial-dragonborn-chromatic-ftd-breath-weapon-2', 'racial-dragonborn-gem-ftd-breath-weapon-2', 'racial-dragonborn-metallic-ftd-breath-weapon-2']),
        ('chromatic-warding-use', 'fixed', 1, 0,
            ARRAY['chromatic-warding', 'racial-dragonborn-chromatic-ftd-chromatic-warding-4']),
        ('metallic-breath-weapon-use', 'fixed', 1, 0,
            ARRAY['metallic-breath-weapon', 'racial-dragonborn-metallic-ftd-metallic-breath-weapon-4'])
    ) AS resources(slug, maximum_type, base_maximum, minimum_maximum, allowed_providers)
    LOOP
        SELECT id INTO pool_id FROM trackable_resource_definition WHERE slug = pool.slug;
        IF pool_id IS NULL OR NOT EXISTS (SELECT 1 FROM trackable_resource_definition WHERE id = pool_id
            AND maximum_type = pool.maximum_type AND base_maximum = pool.base_maximum
            AND minimum_maximum = pool.minimum_maximum AND multiplier = 1
            AND scaling_ability IS NULL AND recharge_type = 'long-rest') THEN
            RAISE EXCEPTION 'FTD resources: missing resource or unexpected configuration for %', pool.slug;
        END IF;
        IF EXISTS (SELECT 1 FROM trackable_resource_rule WHERE resource_definition_id = pool_id)
            OR EXISTS (SELECT 1 FROM character_feature_definition WHERE resource_definition_id = pool_id
                AND NOT (slug = ANY(pool.allowed_providers))) THEN
            RAISE EXCEPTION 'FTD resources: unexpected independent rule or provider for %', pool.slug;
        END IF;
    END LOOP;

    FOR provider IN SELECT * FROM (VALUES
        ('breath-weapon-uses', 'breath-weapon', ARRAY['dragonborn', 'chromatic-dragonborn-red', 'metallic-dragonborn-silver'], 1, true),
        ('breath-weapon-uses', 'racial-dragonborn-chromatic-ftd-breath-weapon-2', ARRAY['dragonborn-chromatic-ftd'], 1, false),
        ('breath-weapon-uses', 'racial-dragonborn-gem-ftd-breath-weapon-2', ARRAY['dragonborn-gem-ftd'], 1, false),
        ('breath-weapon-uses', 'racial-dragonborn-metallic-ftd-breath-weapon-2', ARRAY['dragonborn-metallic-ftd'], 1, false),
        ('chromatic-warding-use', 'chromatic-warding', ARRAY['chromatic-dragonborn-red'], 5, true),
        ('chromatic-warding-use', 'racial-dragonborn-chromatic-ftd-chromatic-warding-4', ARRAY['dragonborn-chromatic-ftd'], 5, false),
        ('metallic-breath-weapon-use', 'metallic-breath-weapon', ARRAY['metallic-dragonborn-silver'], 5, true),
        ('metallic-breath-weapon-use', 'racial-dragonborn-metallic-ftd-metallic-breath-weapon-4', ARRAY['dragonborn-metallic-ftd'], 5, false)
    ) AS providers(pool_slug, feature_slug, race_slugs, acquisition, legacy)
    LOOP
        SELECT id INTO pool_id FROM trackable_resource_definition WHERE slug = provider.pool_slug;
        SELECT id INTO feature_id FROM character_feature_definition WHERE slug = provider.feature_slug;
        IF feature_id IS NULL THEN
            RAISE EXCEPTION 'FTD resources: missing feature %', provider.feature_slug;
        END IF;
        IF EXISTS (SELECT 1 FROM character_feature_definition WHERE id = feature_id
            AND ((provider.legacy AND resource_definition_id IS DISTINCT FROM pool_id)
                OR (resource_definition_id IS NOT NULL AND resource_definition_id <> pool_id))) THEN
            RAISE EXCEPTION 'FTD resources: unexpected feature resource for %', provider.feature_slug;
        END IF;
        IF (SELECT count(*) FROM character_feature_rule WHERE feature_definition_id = feature_id) <> cardinality(provider.race_slugs)
            OR (SELECT count(DISTINCT r.slug) FROM character_feature_rule fr
                JOIN character_race r ON r.id = fr.character_race_id
                WHERE fr.feature_definition_id = feature_id AND r.slug = ANY(provider.race_slugs)
                    AND fr.unlock_level = provider.acquisition AND fr.character_class_id IS NULL
                    AND fr.character_subclass_id IS NULL AND fr.feat_id IS NULL
                    AND fr.progression_definition_id IS NULL AND fr.progression_threshold IS NULL) <> cardinality(provider.race_slugs) THEN
            RAISE EXCEPTION 'FTD resources: unexpected racial assignments for %', provider.feature_slug;
        END IF;

        -- Never detach the still-used old providers. Correct links are a no-op.
        IF NOT provider.legacy THEN
            UPDATE character_feature_definition SET resource_definition_id = pool_id
                WHERE id = feature_id AND resource_definition_id IS DISTINCT FROM pool_id;
        END IF;
    END LOOP;
END $$
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les cinq liens peuvent préexister ; leur retrait doit être décidé explicitement.');
    }
}

BEGIN;

-- Capacités générales du Clerc

WITH class_rules (
    class_slug,
    feature_slug,
    unlock_level,
    display_order
) AS (
    VALUES
        ('cleric', 'cleric-spellcasting', 1, 10),
        ('cleric', 'channel-divinity', 2, 20),
        ('cleric', 'turn-undead', 2, 30),
        ('cleric', 'destroy-undead', 5, 50),
        ('cleric', 'divine-intervention', 10, 100),
        ('cleric', 'improved-divine-intervention', 20, 200)
)
INSERT INTO character_feature_rule (
    feature_definition_id,
    character_class_id,
    character_subclass_id,
    character_race_id,
    feat_id,
    unlock_level,
    display_order
)
SELECT
    feature.id,
    character_class.id,
    NULL,
    NULL,
    NULL,
    class_rules.unlock_level,
    class_rules.display_order
FROM class_rules
INNER JOIN character_class
    ON character_class.slug = class_rules.class_slug
INNER JOIN character_feature_definition feature
    ON feature.slug = class_rules.feature_slug
ON CONFLICT (
    character_class_id,
    feature_definition_id,
    unlock_level
)
DO UPDATE SET
    display_order = EXCLUDED.display_order;

-- Capacités des domaines

WITH subclass_rules (
    class_slug,
    subclass_slug,
    feature_slug,
    unlock_level,
    display_order
) AS (
    VALUES
        -- Lumière
        ('cleric', 'light-domain', 'light-domain-bonus-cantrip', 1, 10),
        ('cleric', 'light-domain', 'warding-flare', 1, 20),
        ('cleric', 'light-domain', 'radiance-of-the-dawn', 2, 30),
        ('cleric', 'light-domain', 'improved-flare', 6, 60),
        ('cleric', 'light-domain', 'potent-spellcasting-light', 8, 80),
        ('cleric', 'light-domain', 'corona-of-light', 17, 170),

        -- Vie
        ('cleric', 'life-domain', 'life-domain-bonus-proficiency', 1, 10),
        ('cleric', 'life-domain', 'disciple-of-life', 1, 20),
        ('cleric', 'life-domain', 'preserve-life', 2, 30),
        ('cleric', 'life-domain', 'blessed-healer', 6, 60),
        ('cleric', 'life-domain', 'divine-strike-life', 8, 80),
        ('cleric', 'life-domain', 'supreme-healing', 17, 170),

        -- Tombe
        ('cleric', 'grave-domain', 'circle-of-mortality', 1, 10),
        ('cleric', 'grave-domain', 'eyes-of-the-grave', 1, 20),
        ('cleric', 'grave-domain', 'path-to-the-grave', 2, 30),
        ('cleric', 'grave-domain', 'sentinel-at-deaths-door', 6, 60),
        ('cleric', 'grave-domain', 'potent-spellcasting-grave', 8, 80),
        ('cleric', 'grave-domain', 'keeper-of-souls', 17, 170),

        -- Tempête
        (
            'cleric',
            'tempest-domain',
            'tempest-domain-bonus-proficiencies',
            1,
            10
        ),
        ('cleric', 'tempest-domain', 'wrath-of-the-storm', 1, 20),
        ('cleric', 'tempest-domain', 'destructive-wrath', 2, 30),
        ('cleric', 'tempest-domain', 'thunderbolt-strike', 6, 60),
        ('cleric', 'tempest-domain', 'divine-strike-tempest', 8, 80),
        ('cleric', 'tempest-domain', 'stormborn', 17, 170),

        -- Crépuscule
        (
            'cleric',
            'twilight-domain',
            'twilight-domain-bonus-proficiencies',
            1,
            10
        ),
        ('cleric', 'twilight-domain', 'eyes-of-night', 1, 20),
        ('cleric', 'twilight-domain', 'vigilant-blessing', 1, 30),
        ('cleric', 'twilight-domain', 'twilight-sanctuary', 2, 40),
        ('cleric', 'twilight-domain', 'steps-of-night', 6, 60),
        ('cleric', 'twilight-domain', 'divine-strike-twilight', 8, 80),
        ('cleric', 'twilight-domain', 'twilight-shroud', 17, 170)
)
INSERT INTO character_feature_rule (
    feature_definition_id,
    character_class_id,
    character_subclass_id,
    character_race_id,
    feat_id,
    unlock_level,
    display_order
)
SELECT
    feature.id,
    NULL,
    subclass.id,
    NULL,
    NULL,
    subclass_rules.unlock_level,
    subclass_rules.display_order
FROM subclass_rules
INNER JOIN character_class
    ON character_class.slug = subclass_rules.class_slug
INNER JOIN character_subclass subclass
    ON subclass.character_class_id = character_class.id
   AND subclass.slug = subclass_rules.subclass_slug
INNER JOIN character_feature_definition feature
    ON feature.slug = subclass_rules.feature_slug
ON CONFLICT (
    character_subclass_id,
    feature_definition_id,
    unlock_level
)
DO UPDATE SET
    display_order = EXCLUDED.display_order;

-- Progression de Canalisation d’énergie divine

WITH channel_divinity_progression (
    unlock_level,
    maximum_override
) AS (
    VALUES
        (2, 1),
        (6, 2),
        (18, 3)
)
INSERT INTO trackable_resource_rule (
    resource_definition_id,
    character_class_id,
    character_subclass_id,
    character_race_id,
    feat_id,
    unlock_level,
    maximum_override
)
SELECT
    resource.id,
    character_class.id,
    NULL,
    NULL,
    NULL,
    progression.unlock_level,
    progression.maximum_override
FROM channel_divinity_progression progression
INNER JOIN character_class
    ON character_class.slug = 'cleric'
INNER JOIN trackable_resource_definition resource
    ON resource.slug = 'cleric-channel-divinity'
ON CONFLICT (
    character_class_id,
    resource_definition_id,
    unlock_level
)
DO UPDATE SET
    maximum_override = EXCLUDED.maximum_override;

-- Ressources spécifiques aux domaines

WITH subclass_resource_rules (
    class_slug,
    subclass_slug,
    resource_slug,
    unlock_level,
    maximum_override
) AS (
    VALUES
        (
            'cleric',
            'light-domain',
            'warding-flare-uses',
            1,
            NULL
        ),
        (
            'cleric',
            'grave-domain',
            'eyes-of-the-grave-uses',
            1,
            NULL
        ),
        (
            'cleric',
            'grave-domain',
            'sentinel-at-deaths-door-uses',
            6,
            NULL
        ),
        (
            'cleric',
            'tempest-domain',
            'wrath-of-the-storm-uses',
            1,
            NULL
        ),
        (
            'cleric',
            'twilight-domain',
            'steps-of-night-uses',
            6,
            NULL
        )
)
INSERT INTO trackable_resource_rule (
    resource_definition_id,
    character_class_id,
    character_subclass_id,
    character_race_id,
    feat_id,
    unlock_level,
    maximum_override
)
SELECT
    resource.id,
    NULL,
    subclass.id,
    NULL,
    NULL,
    resource_rules.unlock_level,
    resource_rules.maximum_override
FROM subclass_resource_rules resource_rules
INNER JOIN character_class
    ON character_class.slug = resource_rules.class_slug
INNER JOIN character_subclass subclass
    ON subclass.character_class_id = character_class.id
   AND subclass.slug = resource_rules.subclass_slug
INNER JOIN trackable_resource_definition resource
    ON resource.slug = resource_rules.resource_slug
ON CONFLICT (
    character_subclass_id,
    resource_definition_id,
    unlock_level
)
DO UPDATE SET
    maximum_override = EXCLUDED.maximum_override;

COMMIT;
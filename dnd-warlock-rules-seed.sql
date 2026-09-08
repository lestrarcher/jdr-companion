BEGIN;

-- Correction : Répit en bouteille est utilisable une fois par repos long.

UPDATE trackable_resource_definition
SET
    maximum_type = 'fixed',
    base_maximum = 1,
    multiplier = 1,
    minimum_maximum = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE slug = 'bottled-respite-uses';

-- Ressources des Arcanums et de Maître de l’occulte

INSERT INTO trackable_resource_definition (
    slug,
    name,
    description,
    recharge_type,
    maximum_type,
    base_maximum,
    multiplier,
    minimum_maximum,
    scaling_ability,
    custom,
    created_at,
    updated_at
)
VALUES
    (
        'mystic-arcanum-6-use',
        'Utilisation d’Arcanum mystique — niveau 6',
        NULL,
        'long-rest',
        'fixed',
        1,
        1,
        1,
        NULL,
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),
    (
        'mystic-arcanum-7-use',
        'Utilisation d’Arcanum mystique — niveau 7',
        NULL,
        'long-rest',
        'fixed',
        1,
        1,
        1,
        NULL,
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),
    (
        'mystic-arcanum-8-use',
        'Utilisation d’Arcanum mystique — niveau 8',
        NULL,
        'long-rest',
        'fixed',
        1,
        1,
        1,
        NULL,
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),
    (
        'mystic-arcanum-9-use',
        'Utilisation d’Arcanum mystique — niveau 9',
        NULL,
        'long-rest',
        'fixed',
        1,
        1,
        1,
        NULL,
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),
    (
        'eldritch-master-use',
        'Utilisation de Maître de l’occulte',
        NULL,
        'long-rest',
        'fixed',
        1,
        1,
        1,
        NULL,
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    )
ON CONFLICT (slug)
DO UPDATE SET
    name = EXCLUDED.name,
    description = EXCLUDED.description,
    recharge_type = EXCLUDED.recharge_type,
    maximum_type = EXCLUDED.maximum_type,
    base_maximum = EXCLUDED.base_maximum,
    multiplier = EXCLUDED.multiplier,
    minimum_maximum = EXCLUDED.minimum_maximum,
    scaling_ability = EXCLUDED.scaling_ability,
    custom = EXCLUDED.custom,
    updated_at = CURRENT_TIMESTAMP;

-- Liaison des Arcanums à leurs ressources

WITH feature_resources (
    feature_slug,
    resource_slug
) AS (
    VALUES
        ('mystic-arcanum-6', 'mystic-arcanum-6-use'),
        ('mystic-arcanum-7', 'mystic-arcanum-7-use'),
        ('mystic-arcanum-8', 'mystic-arcanum-8-use'),
        ('mystic-arcanum-9', 'mystic-arcanum-9-use'),
        ('eldritch-master', 'eldritch-master-use')
)
UPDATE character_feature_definition feature
SET
    resource_definition_id = resource.id,
    updated_at = CURRENT_TIMESTAMP
FROM feature_resources links
INNER JOIN trackable_resource_definition resource
    ON resource.slug = links.resource_slug
WHERE feature.slug = links.feature_slug;

-- Capacités générales de l’Occultiste

WITH class_rules (
    feature_slug,
    unlock_level,
    display_order
) AS (
    VALUES
        ('pact-magic', 1, 10),
        ('eldritch-invocations', 2, 20),
        ('pact-boon', 3, 30),
        ('mystic-arcanum-6', 11, 110),
        ('mystic-arcanum-7', 13, 130),
        ('mystic-arcanum-8', 15, 150),
        ('mystic-arcanum-9', 17, 170),
        ('eldritch-master', 20, 200)
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
    rules.unlock_level,
    rules.display_order
FROM class_rules rules
INNER JOIN character_class
    ON character_class.slug = 'occultiste'
INNER JOIN character_feature_definition feature
    ON feature.slug = rules.feature_slug
ON CONFLICT (
    character_class_id,
    feature_definition_id,
    unlock_level
)
DO UPDATE SET
    display_order = EXCLUDED.display_order;

-- Capacités des patrons

WITH subclass_rules (
    subclass_slug,
    feature_slug,
    unlock_level,
    display_order
) AS (
    VALUES
        -- Fiélon
        ('fiend', 'dark-ones-blessing', 1, 10),
        ('fiend', 'dark-ones-own-luck', 6, 60),
        ('fiend', 'fiendish-resilience', 10, 100),
        ('fiend', 'hurl-through-hell', 14, 140),

        -- Génie Dao
        ('genie-dao', 'genies-vessel', 1, 10),
        ('genie-dao', 'bottled-respite', 1, 20),
        ('genie-dao', 'genies-wrath-dao', 1, 30),
        ('genie-dao', 'elemental-gift-dao', 6, 60),
        ('genie-dao', 'elemental-gift-flight', 6, 70),
        ('genie-dao', 'sanctuary-vessel', 10, 100),
        ('genie-dao', 'limited-wish', 14, 140),

        -- Mort-vivant
        ('undead', 'form-of-dread', 1, 10),
        ('undead', 'grave-touched', 6, 60),
        ('undead', 'necrotic-husk', 10, 100),
        ('undead', 'spirit-projection', 14, 140),

        -- Magelame
        ('hexblade', 'hexblades-curse', 1, 10),
        ('hexblade', 'hex-warrior', 1, 20),
        ('hexblade', 'accursed-specter', 6, 60),
        ('hexblade', 'armor-of-hexes', 10, 100),
        ('hexblade', 'master-of-hexes', 14, 140)
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
    rules.unlock_level,
    rules.display_order
FROM subclass_rules rules
INNER JOIN character_class
    ON character_class.slug = 'occultiste'
INNER JOIN character_subclass subclass
    ON subclass.character_class_id = character_class.id
   AND subclass.slug = rules.subclass_slug
INNER JOIN character_feature_definition feature
    ON feature.slug = rules.feature_slug
ON CONFLICT (
    character_subclass_id,
    feature_definition_id,
    unlock_level
)
DO UPDATE SET
    display_order = EXCLUDED.display_order;

-- Progression des emplacements de pacte

WITH pact_slot_progression (
    unlock_level,
    maximum_override
) AS (
    VALUES
        (1, 1),
        (2, 2),
        (11, 3),
        (17, 4)
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
FROM pact_slot_progression progression
INNER JOIN character_class
    ON character_class.slug = 'occultiste'
INNER JOIN trackable_resource_definition resource
    ON resource.slug = 'warlock-pact-slots'
ON CONFLICT (
    character_class_id,
    resource_definition_id,
    unlock_level
)
DO UPDATE SET
    maximum_override = EXCLUDED.maximum_override;

-- Ressources générales liées aux niveaux d’Occultiste

WITH class_resource_rules (
    resource_slug,
    unlock_level,
    maximum_override
) AS (
    VALUES
        ('mystic-arcanum-6-use', 11, 1),
        ('mystic-arcanum-7-use', 13, 1),
        ('mystic-arcanum-8-use', 15, 1),
        ('mystic-arcanum-9-use', 17, 1),
        ('eldritch-master-use', 20, 1)
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
    rules.unlock_level,
    rules.maximum_override
FROM class_resource_rules rules
INNER JOIN character_class
    ON character_class.slug = 'occultiste'
INNER JOIN trackable_resource_definition resource
    ON resource.slug = rules.resource_slug
ON CONFLICT (
    character_class_id,
    resource_definition_id,
    unlock_level
)
DO UPDATE SET
    maximum_override = EXCLUDED.maximum_override;

-- Ressources propres aux patrons

WITH subclass_resource_rules (
    subclass_slug,
    resource_slug,
    unlock_level,
    maximum_override
) AS (
    VALUES
        ('fiend', 'dark-ones-own-luck-use', 6, 1),
        ('fiend', 'hurl-through-hell-use', 14, 1),

        ('genie-dao', 'bottled-respite-uses', 1, 1),
        ('genie-dao', 'elemental-gift-flight-uses', 6, NULL),

        ('undead', 'form-of-dread-uses', 1, NULL),
        ('undead', 'spirit-projection-use', 14, 1),

        ('hexblade', 'hexblades-curse-use', 1, 1),
        ('hexblade', 'accursed-specter-use', 6, 1)
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
    rules.unlock_level,
    rules.maximum_override
FROM subclass_resource_rules rules
INNER JOIN character_class
    ON character_class.slug = 'occultiste'
INNER JOIN character_subclass subclass
    ON subclass.character_class_id = character_class.id
   AND subclass.slug = rules.subclass_slug
INNER JOIN trackable_resource_definition resource
    ON resource.slug = rules.resource_slug
ON CONFLICT (
    character_subclass_id,
    resource_definition_id,
    unlock_level
)
DO UPDATE SET
    maximum_override = EXCLUDED.maximum_override;

COMMIT;
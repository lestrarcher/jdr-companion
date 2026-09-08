BEGIN;

-- Capacités générales de l’Ensorceleur

WITH class_rules (
    class_slug,
    feature_slug,
    unlock_level,
    display_order
) AS (
    VALUES
        ('sorcerer', 'sorcerer-spellcasting', 1, 10),
        ('sorcerer', 'font-of-magic', 2, 20),
        ('sorcerer', 'metamagic', 3, 30),
        ('sorcerer', 'sorcerous-restoration', 20, 200)
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

-- Capacités des sous-classes

WITH subclass_rules (
    class_slug,
    subclass_slug,
    feature_slug,
    unlock_level,
    display_order
) AS (
    VALUES
        (
            'sorcerer',
            'draconic-bloodline',
            'dragon-ancestor',
            1,
            10
        ),
        (
            'sorcerer',
            'draconic-bloodline',
            'draconic-resilience',
            1,
            20
        ),
        (
            'sorcerer',
            'draconic-bloodline',
            'elemental-affinity',
            6,
            60
        ),
        (
            'sorcerer',
            'draconic-bloodline',
            'dragon-wings',
            14,
            140
        ),
        (
            'sorcerer',
            'draconic-bloodline',
            'draconic-presence',
            18,
            180
        ),

        (
            'sorcerer',
            'wild-magic',
            'wild-magic-surge',
            1,
            10
        ),
        (
            'sorcerer',
            'wild-magic',
            'tides-of-chaos',
            1,
            20
        ),
        (
            'sorcerer',
            'wild-magic',
            'bend-luck',
            6,
            60
        ),
        (
            'sorcerer',
            'wild-magic',
            'controlled-chaos',
            14,
            140
        ),
        (
            'sorcerer',
            'wild-magic',
            'spell-bombardment',
            18,
            180
        ),

        (
            'sorcerer',
            'divine-soul',
            'divine-magic',
            1,
            10
        ),
        (
            'sorcerer',
            'divine-soul',
            'favored-by-the-gods',
            1,
            20
        ),
        (
            'sorcerer',
            'divine-soul',
            'empowered-healing',
            6,
            60
        ),
        (
            'sorcerer',
            'divine-soul',
            'otherworldly-wings',
            14,
            140
        ),
        (
            'sorcerer',
            'divine-soul',
            'unearthly-recovery',
            18,
            180
        ),

        (
            'sorcerer',
            'storm-sorcery',
            'wind-speaker',
            1,
            10
        ),
        (
            'sorcerer',
            'storm-sorcery',
            'tempestuous-magic',
            1,
            20
        ),
        (
            'sorcerer',
            'storm-sorcery',
            'heart-of-the-storm',
            6,
            60
        ),
        (
            'sorcerer',
            'storm-sorcery',
            'storm-guide',
            6,
            70
        ),
        (
            'sorcerer',
            'storm-sorcery',
            'storms-fury',
            14,
            140
        ),
        (
            'sorcerer',
            'storm-sorcery',
            'wind-soul',
            18,
            180
        )
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

-- Progression des points de sorcellerie :
-- le maximum correspond au niveau d’Ensorceleur, du niveau 2 au niveau 20.

WITH sorcery_point_progression (
    unlock_level,
    maximum_override
) AS (
    VALUES
        (2, 2),
        (3, 3),
        (4, 4),
        (5, 5),
        (6, 6),
        (7, 7),
        (8, 8),
        (9, 9),
        (10, 10),
        (11, 11),
        (12, 12),
        (13, 13),
        (14, 14),
        (15, 15),
        (16, 16),
        (17, 17),
        (18, 18),
        (19, 19),
        (20, 20)
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
FROM sorcery_point_progression progression
INNER JOIN character_class
    ON character_class.slug = 'sorcerer'
INNER JOIN trackable_resource_definition resource
    ON resource.slug = 'sorcery-points'
ON CONFLICT (
    character_class_id,
    resource_definition_id,
    unlock_level
)
DO UPDATE SET
    maximum_override = EXCLUDED.maximum_override;

-- Ressources propres aux sous-classes

WITH subclass_resource_rules (
    class_slug,
    subclass_slug,
    resource_slug,
    unlock_level,
    maximum_override
) AS (
    VALUES
        (
            'sorcerer',
            'wild-magic',
            'tides-of-chaos-use',
            1,
            1
        ),
        (
            'sorcerer',
            'divine-soul',
            'favored-by-the-gods-use',
            1,
            1
        ),
        (
            'sorcerer',
            'divine-soul',
            'unearthly-recovery-use',
            18,
            1
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
    rules.unlock_level,
    rules.maximum_override
FROM subclass_resource_rules rules
INNER JOIN character_class
    ON character_class.slug = rules.class_slug
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
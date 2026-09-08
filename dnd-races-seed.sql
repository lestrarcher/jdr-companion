BEGIN;

-- Races principales et lignées autonomes

INSERT INTO character_race (
    slug,
    name,
    description,
    custom,
    created_at,
    updated_at,
    parent_race_id,
    feat_choice_count
)
VALUES
    (
        'half-orc',
        'Demi-orc',
        NULL,
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP,
        NULL,
        0
    ),
    (
        'reborn',
        'Ressuscité',
        NULL,
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP,
        NULL,
        0
    ),
    (
        'halfling',
        'Halfelin',
        NULL,
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP,
        NULL,
        0
    ),
    (
        'dragonborn',
        'Drakéide',
        NULL,
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP,
        NULL,
        0
    ),
    (
        'shadar-kai',
        'Shadar-kai',
        NULL,
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP,
        NULL,
        0
    )
ON CONFLICT (slug)
DO UPDATE SET
    name = EXCLUDED.name,
    description = EXCLUDED.description,
    custom = EXCLUDED.custom,
    feat_choice_count = EXCLUDED.feat_choice_count,
    updated_at = CURRENT_TIMESTAMP;

-- Héritages drakéides

INSERT INTO character_race (
    slug,
    name,
    description,
    custom,
    created_at,
    updated_at,
    parent_race_id,
    feat_choice_count
)
SELECT
    child.slug,
    child.name,
    NULL,
    false,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP,
    parent.id,
    0
FROM (
    VALUES
        (
            'metallic-dragonborn-silver',
            'Drakéide métallique — Argent'
        ),
        (
            'chromatic-dragonborn-red',
            'Drakéide chromatique — Rouge'
        )
) AS child (slug, name)
INNER JOIN character_race parent
    ON parent.slug = 'dragonborn'
ON CONFLICT (slug)
DO UPDATE SET
    name = EXCLUDED.name,
    parent_race_id = EXCLUDED.parent_race_id,
    custom = EXCLUDED.custom,
    feat_choice_count = EXCLUDED.feat_choice_count,
    updated_at = CURRENT_TIMESTAMP;

-- Bonus fixes et bonus flexibles

WITH modifiers (
    race_slug,
    ability,
    value,
    choice_key
) AS (
    VALUES
        (
            'half-orc',
            'strength',
            2,
            NULL
        ),
        (
            'half-orc',
            'constitution',
            1,
            NULL
        ),
        (
            'halfling',
            'dexterity',
            2,
            NULL
        ),
        (
            'reborn',
            NULL,
            2,
            'primary'
        ),
        (
            'reborn',
            NULL,
            1,
            'secondary'
        ),
        (
            'dragonborn',
            NULL,
            2,
            'primary'
        ),
        (
            'dragonborn',
            NULL,
            1,
            'secondary'
        ),
        (
            'shadar-kai',
            NULL,
            2,
            'primary'
        ),
        (
            'shadar-kai',
            NULL,
            1,
            'secondary'
        )
)
INSERT INTO race_ability_modifier (
    race_id,
    ability,
    value,
    choice_key
)
SELECT
    race.id,
    modifiers.ability,
    modifiers.value,
    modifiers.choice_key
FROM modifiers
INNER JOIN character_race race
    ON race.slug = modifiers.race_slug
WHERE NOT EXISTS (
    SELECT 1
    FROM race_ability_modifier existing_modifier
    WHERE existing_modifier.race_id = race.id
      AND existing_modifier.ability
          IS NOT DISTINCT FROM modifiers.ability
      AND existing_modifier.value = modifiers.value
      AND existing_modifier.choice_key
          IS NOT DISTINCT FROM modifiers.choice_key
);

COMMIT;
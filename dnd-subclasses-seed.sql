BEGIN;

INSERT INTO character_subclass (
    character_class_id,
    slug,
    name,
    spellcasting_progression,
    description,
    custom,
    created_at,
    updated_at
)
SELECT
    character_class.id,
    subclass.slug,
    subclass.name,
    subclass.spellcasting_progression,
    subclass.description,
    subclass.custom,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM (
    VALUES
        (
            'sorcerer',
            'draconic-bloodline',
            'Lignée draconique',
            NULL,
            NULL,
            false
        ),
        (
            'sorcerer',
            'wild-magic',
            'Magie sauvage',
            NULL,
            NULL,
            false
        ),
        (
            'sorcerer',
            'divine-soul',
            'Âme divine',
            NULL,
            NULL,
            false
        ),
        (
            'sorcerer',
            'storm-sorcery',
            'Sorcellerie des tempêtes',
            NULL,
            NULL,
            false
        ),

        (
            'cleric',
            'life-domain',
            'Domaine de la Vie',
            NULL,
            NULL,
            false
        ),
        (
            'cleric',
            'grave-domain',
            'Domaine de la Tombe',
            NULL,
            NULL,
            false
        ),
        (
            'cleric',
            'tempest-domain',
            'Domaine de la Tempête',
            NULL,
            NULL,
            false
        ),
        (
            'cleric',
            'twilight-domain',
            'Domaine du Crépuscule',
            NULL,
            NULL,
            false
        ),

        (
            'occultiste',
            'fiend',
            'Le Fiélon',
            NULL,
            NULL,
            false
        ),
        (
            'occultiste',
            'genie-dao',
            'Le Génie — Dao',
            NULL,
            NULL,
            false
        ),
        (
            'occultiste',
            'arch-hag',
            'L’Archi-guenaude',
            NULL,
            NULL,
            true
        ),
        (
            'occultiste',
            'undead',
            'Le Mort-vivant',
            NULL,
            NULL,
            false
        ),
        (
            'occultiste',
            'hexblade',
            'La Lame maudite',
            NULL,
            NULL,
            false
        ),

        (
            'monk',
            'open-hand',
            'Voie de la Main ouverte',
            NULL,
            NULL,
            false
        ),
        (
            'monk',
            'shadow',
            'Voie de l’Ombre',
            NULL,
            NULL,
            false
        ),

        (
            'rodeur',
            'monster-slayer',
            'Tueur de monstres',
            NULL,
            NULL,
            false
        ),

        (
            'fighter',
            'champion',
            'Champion',
            NULL,
            NULL,
            false
        ),
        (
            'fighter',
            'battle-master',
            'Maître de guerre',
            NULL,
            NULL,
            false
        ),
        (
            'fighter',
            'echo-knight',
            'Chevalier des échos',
            NULL,
            NULL,
            false
        ),

        (
            'rogue',
            'assassin',
            'Assassin',
            NULL,
            NULL,
            false
        ),

        (
            'druide',
            'circle-of-the-shepherd',
            'Cercle des Bergers',
            NULL,
            NULL,
            false
        ),

        (
            'barde',
            'college-of-eloquence',
            'Collège de l’Éloquence',
            NULL,
            NULL,
            false
        ),
        (
            'barde',
            'college-of-glamour',
            'Collège de la Séduction',
            NULL,
            NULL,
            false
        )
) AS subclass (
    class_slug,
    slug,
    name,
    spellcasting_progression,
    description,
    custom
)
INNER JOIN character_class
    ON character_class.slug = subclass.class_slug
ON CONFLICT (character_class_id, slug)
DO UPDATE SET
    name = EXCLUDED.name,
    spellcasting_progression = EXCLUDED.spellcasting_progression,
    description = EXCLUDED.description,
    custom = EXCLUDED.custom,
    updated_at = CURRENT_TIMESTAMP;

COMMIT;
BEGIN;

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
        'warlock-pact-slots',
        'Emplacements de magie de pacte',
        'Emplacements de sorts d’Occultiste récupérés après un repos court ou long.',
        'short-rest',
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
        'dark-ones-own-luck-use',
        'Utilisation de Chance du Ténébreux',
        'Utilisation disponible de Chance du Ténébreux.',
        'short-rest',
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
        'hurl-through-hell-use',
        'Utilisation de Traversée des Enfers',
        'Utilisation disponible de Traversée des Enfers.',
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
        'bottled-respite-uses',
        'Utilisations de Répit en bouteille',
        'Nombre d’utilisations de Répit en bouteille.',
        'long-rest',
        'proficiency-bonus',
        0,
        1,
        1,
        NULL,
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),
    (
        'elemental-gift-flight-uses',
        'Utilisations du vol du Don élémentaire',
        'Nombre d’utilisations permettant d’obtenir une vitesse de vol.',
        'long-rest',
        'proficiency-bonus',
        0,
        1,
        1,
        NULL,
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),
    (
        'form-of-dread-uses',
        'Utilisations de Forme d’effroi',
        'Nombre d’utilisations de Forme d’effroi.',
        'long-rest',
        'proficiency-bonus',
        0,
        1,
        1,
        NULL,
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),
    (
        'spirit-projection-use',
        'Utilisation de Projection spirituelle',
        'Utilisation disponible de Projection spirituelle.',
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
        'hexblades-curse-use',
        'Utilisation de Malédiction du Magelame',
        'Utilisation disponible de Malédiction du Magelame.',
        'short-rest',
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
        'accursed-specter-use',
        'Utilisation de Spectre maudit',
        'Utilisation disponible de Spectre maudit.',
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

WITH features (
    slug,
    name,
    description,
    activation_type,
    visible,
    custom,
    resource_slug
) AS (
    VALUES
        -- Occultiste
        (
            'pact-magic',
            'Magie de pacte',
            'Vous lancez vos sorts d’Occultiste avec des emplacements qui se récupèrent après un repos court ou long.',
            'passive',
            true,
            false,
            'warlock-pact-slots'
        ),
        (
            'eldritch-invocations',
            'Manifestations occultes',
            'Vous choisissez des manifestations qui modifient vos pouvoirs ou vous accordent de nouvelles capacités.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'pact-boon',
            'Faveur de pacte',
            'Vous choisissez une faveur accordée par votre patron, comme le Pacte de la Lame, de la Chaîne ou du Grimoire.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'mystic-arcanum-6',
            'Arcanum mystique — niveau 6',
            'Vous choisissez un sort de niveau 6 utilisable une fois par repos long sans dépenser d’emplacement.',
            'action',
            true,
            false,
            NULL
        ),
        (
            'mystic-arcanum-7',
            'Arcanum mystique — niveau 7',
            'Vous choisissez un sort de niveau 7 utilisable une fois par repos long sans dépenser d’emplacement.',
            'action',
            true,
            false,
            NULL
        ),
        (
            'mystic-arcanum-8',
            'Arcanum mystique — niveau 8',
            'Vous choisissez un sort de niveau 8 utilisable une fois par repos long sans dépenser d’emplacement.',
            'action',
            true,
            false,
            NULL
        ),
        (
            'mystic-arcanum-9',
            'Arcanum mystique — niveau 9',
            'Vous choisissez un sort de niveau 9 utilisable une fois par repos long sans dépenser d’emplacement.',
            'action',
            true,
            false,
            NULL
        ),
        (
            'eldritch-master',
            'Maître de l’occulte',
            'Après une minute de communion avec votre patron, vous récupérez vos emplacements de magie de pacte.',
            'action',
            true,
            false,
            NULL
        ),

        -- Fiélon
        (
            'dark-ones-blessing',
            'Bénédiction du Ténébreux',
            'Lorsque vous réduisez une créature hostile à 0 point de vie, vous gagnez des points de vie temporaires.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'dark-ones-own-luck',
            'Chance du Ténébreux',
            'Vous ajoutez 1d10 à un test de caractéristique ou à un jet de sauvegarde.',
            'free_action',
            true,
            false,
            'dark-ones-own-luck-use'
        ),
        (
            'fiendish-resilience',
            'Résistance fiélonne',
            'Après un repos, vous choisissez un type de dégâts auquel vous devenez résistant.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'hurl-through-hell',
            'Traversée des Enfers',
            'Lorsque vous touchez une créature, vous pouvez la projeter temporairement à travers les plans inférieurs.',
            'free_action',
            true,
            false,
            'hurl-through-hell-use'
        ),

        -- Génie Dao
        (
            'genies-vessel',
            'Réceptacle du génie',
            'Votre patron vous accorde un petit réceptacle magique servant de foyer à plusieurs pouvoirs.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'bottled-respite',
            'Répit en bouteille',
            'Vous disparaissez dans le réceptacle de votre génie et pouvez y rester plusieurs heures.',
            'action',
            true,
            false,
            'bottled-respite-uses'
        ),
        (
            'genies-wrath-dao',
            'Colère du génie — Dao',
            'Une fois par tour, une attaque peut infliger des dégâts contondants supplémentaires.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'elemental-gift-dao',
            'Don élémentaire — Dao',
            'Vous obtenez une résistance aux dégâts contondants.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'elemental-gift-flight',
            'Vol du Don élémentaire',
            'Vous obtenez temporairement une vitesse de vol stationnaire.',
            'bonus_action',
            true,
            false,
            'elemental-gift-flight-uses'
        ),
        (
            'sanctuary-vessel',
            'Réceptacle sanctuaire',
            'Vous pouvez accueillir plusieurs créatures dans votre réceptacle et améliorer leur repos court.',
            'action',
            true,
            false,
            NULL
        ),
        (
            'limited-wish',
            'Souhait limité',
            'Vous demandez à votre patron de reproduire l’effet d’un sort de niveau 6 ou inférieur.',
            'action',
            true,
            false,
            NULL
        ),

        -- Mort-vivant
        (
            'form-of-dread',
            'Forme d’effroi',
            'Vous adoptez une apparence terrifiante, gagnez des points de vie temporaires et pouvez effrayer vos ennemis.',
            'bonus_action',
            true,
            false,
            'form-of-dread-uses'
        ),
        (
            'grave-touched',
            'Contact avec la tombe',
            'Vous n’avez plus besoin de respirer et pouvez convertir certains dégâts en dégâts nécrotiques.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'necrotic-husk',
            'Enveloppe nécrotique',
            'Vous résistez aux dégâts nécrotiques et pouvez éviter de tomber inconscient en libérant une énergie funeste.',
            'reaction',
            true,
            false,
            NULL
        ),
        (
            'spirit-projection',
            'Projection spirituelle',
            'Votre esprit quitte votre corps et obtient plusieurs propriétés surnaturelles.',
            'action',
            true,
            false,
            'spirit-projection-use'
        ),

        -- Magelame
        (
            'hexblades-curse',
            'Malédiction du Magelame',
            'Vous maudissez une créature afin d’améliorer vos attaques contre elle et de récupérer des points de vie à sa mort.',
            'bonus_action',
            true,
            false,
            'hexblades-curse-use'
        ),
        (
            'hex-warrior',
            'Guerrier maudit',
            'Vous obtenez des maîtrises martiales et pouvez utiliser votre Charisme avec une arme choisie.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'accursed-specter',
            'Spectre maudit',
            'Vous liez temporairement l’âme d’un humanoïde vaincu afin qu’elle combatte à vos côtés.',
            'passive',
            true,
            false,
            'accursed-specter-use'
        ),
        (
            'armor-of-hexes',
            'Armure de malédictions',
            'Une cible affectée par votre Malédiction du Magelame peut manquer son attaque contre vous.',
            'reaction',
            true,
            false,
            NULL
        ),
        (
            'master-of-hexes',
            'Maître des malédictions',
            'Vous pouvez transférer votre Malédiction du Magelame lorsqu’une cible maudite meurt.',
            'bonus_action',
            true,
            false,
            NULL
        )
)
INSERT INTO character_feature_definition (
    slug,
    name,
    description,
    activation_type,
    visible,
    custom,
    created_at,
    updated_at,
    resource_definition_id
)
SELECT
    features.slug,
    features.name,
    features.description,
    features.activation_type,
    features.visible,
    features.custom,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP,
    resource.id
FROM features
LEFT JOIN trackable_resource_definition resource
    ON resource.slug = features.resource_slug
ON CONFLICT (slug)
DO UPDATE SET
    name = EXCLUDED.name,
    description = EXCLUDED.description,
    activation_type = EXCLUDED.activation_type,
    visible = EXCLUDED.visible,
    custom = EXCLUDED.custom,
    resource_definition_id = EXCLUDED.resource_definition_id,
    updated_at = CURRENT_TIMESTAMP;

COMMIT;
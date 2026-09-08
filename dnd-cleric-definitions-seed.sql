BEGIN;

-- Ressources générales et ressources des domaines

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
        'cleric-channel-divinity',
        'Canalisation d’énergie divine',
        'Utilisations disponibles des effets de Canalisation d’énergie divine.',
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
        'warding-flare-uses',
        'Utilisations de Lueur protectrice',
        'Nombre d’utilisations de Lueur protectrice.',
        'long-rest',
        'ability_modifier',
        0,
        1,
        1,
        'wisdom',
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),
    (
        'eyes-of-the-grave-uses',
        'Utilisations d’ regard de la tombe',
        'Nombre d’utilisations du Regard de la tombe.',
        'long-rest',
        'ability_modifier',
        0,
        1,
        1,
        'wisdom',
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),
    (
        'sentinel-at-deaths-door-uses',
        'Utilisations de Sentinelle aux portes de la mort',
        'Nombre d’utilisations de Sentinelle aux portes de la mort.',
        'long-rest',
        'ability_modifier',
        0,
        1,
        1,
        'wisdom',
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),
    (
        'wrath-of-the-storm-uses',
        'Utilisations de Colère de l’orage',
        'Nombre d’utilisations de Colère de l’orage.',
        'long-rest',
        'ability_modifier',
        0,
        1,
        1,
        'wisdom',
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),
    (
        'steps-of-night-uses',
        'Utilisations de Pas de la nuit',
        'Nombre d’utilisations de Pas de la nuit.',
        'long-rest',
        'proficiency-bonus',
        0,
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
        -- Clerc
        (
            'cleric-spellcasting',
            'Incantation — Clerc',
            'Vous préparez et lancez des sorts de Clerc en utilisant la Sagesse.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'channel-divinity',
            'Canalisation d’énergie divine',
            'Vous canalisez une énergie divine pour produire différents effets accordés par votre classe et votre domaine.',
            'passive',
            true,
            false,
            'cleric-channel-divinity'
        ),
        (
            'turn-undead',
            'Renvoi des morts-vivants',
            'Vous dépensez une Canalisation d’énergie divine pour repousser les morts-vivants proches.',
            'action',
            true,
            false,
            'cleric-channel-divinity'
        ),
        (
            'destroy-undead',
            'Destruction des morts-vivants',
            'Les morts-vivants suffisamment faibles qui échouent contre votre Renvoi sont immédiatement détruits.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'divine-intervention',
            'Intervention divine',
            'Vous demandez à votre divinité d’intervenir directement. Les chances de réussite dépendent de votre niveau de Clerc.',
            'action',
            true,
            false,
            NULL
        ),
        (
            'improved-divine-intervention',
            'Intervention divine améliorée',
            'Votre appel à votre divinité réussit automatiquement.',
            'passive',
            true,
            false,
            NULL
        ),

        -- Domaine de la Lumière
        (
            'light-domain-bonus-cantrip',
            'Sort mineur supplémentaire',
            'Vous apprenez le sort mineur Lumière s’il ne fait pas déjà partie de vos sorts connus.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'warding-flare',
            'Lueur protectrice',
            'Vous imposez un désavantage à une attaque dirigée contre vous en faisant jaillir une lumière divine.',
            'reaction',
            true,
            false,
            'warding-flare-uses'
        ),
        (
            'radiance-of-the-dawn',
            'Radiance de l’aube',
            'Vous dépensez une Canalisation d’énergie divine pour dissiper les ténèbres et infliger des dégâts radiants.',
            'action',
            true,
            false,
            'cleric-channel-divinity'
        ),
        (
            'improved-flare',
            'Lueur protectrice améliorée',
            'Vous pouvez utiliser Lueur protectrice pour défendre une créature proche.',
            'reaction',
            true,
            false,
            NULL
        ),
        (
            'potent-spellcasting-light',
            'Incantation puissante — Lumière',
            'Vous ajoutez votre modificateur de Sagesse aux dégâts de vos sorts mineurs de Clerc.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'corona-of-light',
            'Couronne de lumière',
            'Vous émettez une lumière intense qui rend les ennemis plus vulnérables à certains sorts de feu et radiants.',
            'action',
            true,
            false,
            NULL
        ),

        -- Domaine de la Vie
        (
            'life-domain-bonus-proficiency',
            'Maîtrise supplémentaire — Vie',
            'Vous obtenez la maîtrise des armures lourdes.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'disciple-of-life',
            'Disciple de la vie',
            'Vos sorts de soin restaurent des points de vie supplémentaires.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'preserve-life',
            'Préservation de la vie',
            'Vous dépensez une Canalisation d’énergie divine pour répartir des soins entre plusieurs créatures.',
            'action',
            true,
            false,
            'cleric-channel-divinity'
        ),
        (
            'blessed-healer',
            'Guérisseur béni',
            'Lorsque vous soignez une autre créature avec un sort, vous récupérez également des points de vie.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'divine-strike-life',
            'Frappe divine — Vie',
            'Une fois par tour, une attaque avec une arme peut infliger des dégâts radiants supplémentaires.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'supreme-healing',
            'Guérison suprême',
            'Les dés de vos sorts de soin produisent automatiquement leur valeur maximale.',
            'passive',
            true,
            false,
            NULL
        ),

        -- Domaine de la Tombe
        (
            'circle-of-mortality',
            'Cercle de mortalité',
            'Vos soins sont particulièrement efficaces sur les créatures à 0 point de vie et vous améliorez l’utilisation de Stabilisation.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'eyes-of-the-grave',
            'Regard de la tombe',
            'Vous détectez brièvement la présence de morts-vivants qui ne sont pas protégés contre la divination.',
            'action',
            true,
            false,
            'eyes-of-the-grave-uses'
        ),
        (
            'path-to-the-grave',
            'Voie vers la tombe',
            'Vous dépensez une Canalisation d’énergie divine pour rendre une créature vulnérable aux dégâts de la prochaine attaque.',
            'action',
            true,
            false,
            'cleric-channel-divinity'
        ),
        (
            'sentinel-at-deaths-door',
            'Sentinelle aux portes de la mort',
            'Vous pouvez transformer un coup critique contre une créature proche en attaque normale.',
            'reaction',
            true,
            false,
            'sentinel-at-deaths-door-uses'
        ),
        (
            'potent-spellcasting-grave',
            'Incantation puissante — Tombe',
            'Vous ajoutez votre modificateur de Sagesse aux dégâts de vos sorts mineurs de Clerc.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'keeper-of-souls',
            'Gardien des âmes',
            'La mort d’un ennemi proche peut restaurer des points de vie à une créature de votre choix.',
            'passive',
            true,
            false,
            NULL
        ),

        -- Domaine de la Tempête
        (
            'tempest-domain-bonus-proficiencies',
            'Maîtrises supplémentaires — Tempête',
            'Vous obtenez la maîtrise des armes de guerre et des armures lourdes.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'wrath-of-the-storm',
            'Colère de l’orage',
            'Lorsqu’une créature proche vous touche, vous pouvez lui infliger des dégâts de foudre ou de tonnerre.',
            'reaction',
            true,
            false,
            'wrath-of-the-storm-uses'
        ),
        (
            'destructive-wrath',
            'Colère destructrice',
            'Vous dépensez une Canalisation d’énergie divine pour maximiser des dégâts de foudre ou de tonnerre.',
            'free_action',
            true,
            false,
            'cleric-channel-divinity'
        ),
        (
            'thunderbolt-strike',
            'Frappe de tonnerre',
            'Lorsque vous infligez des dégâts de foudre à une créature, vous pouvez la repousser.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'divine-strike-tempest',
            'Frappe divine — Tempête',
            'Une fois par tour, une attaque avec une arme peut infliger des dégâts de tonnerre supplémentaires.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'stormborn',
            'Enfant de la tempête',
            'Vous obtenez une vitesse de vol lorsque vous vous trouvez à l’extérieur et que vous n’êtes pas sous terre.',
            'passive',
            true,
            false,
            NULL
        ),

        -- Domaine du Crépuscule
        (
            'twilight-domain-bonus-proficiencies',
            'Maîtrises supplémentaires — Crépuscule',
            'Vous obtenez la maîtrise des armes de guerre et des armures lourdes.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'eyes-of-night',
            'Yeux de la nuit',
            'Vous possédez une vision dans le noir exceptionnelle et pouvez temporairement la partager.',
            'action',
            true,
            false,
            NULL
        ),
        (
            'vigilant-blessing',
            'Bénédiction vigilante',
            'Vous accordez à une créature un avantage à son prochain jet d’initiative.',
            'action',
            true,
            false,
            NULL
        ),
        (
            'twilight-sanctuary',
            'Sanctuaire du crépuscule',
            'Vous dépensez une Canalisation d’énergie divine pour créer une sphère protectrice autour de vous.',
            'action',
            true,
            false,
            'cleric-channel-divinity'
        ),
        (
            'steps-of-night',
            'Pas de la nuit',
            'Dans une lumière faible ou dans les ténèbres, vous obtenez temporairement une vitesse de vol.',
            'bonus_action',
            true,
            false,
            'steps-of-night-uses'
        ),
        (
            'divine-strike-twilight',
            'Frappe divine — Crépuscule',
            'Une fois par tour, une attaque avec une arme peut infliger des dégâts psychiques supplémentaires.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'twilight-shroud',
            'Voile du crépuscule',
            'Les créatures protégées par votre Sanctuaire du crépuscule bénéficient d’un abri partiel.',
            'passive',
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
BEGIN;

-- Ressources de l’Ensorceleur

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
        'sorcery-points',
        'Points de sorcellerie',
        'Réserve utilisée pour la création d’emplacements et la métamagie.',
        'long-rest',
        'fixed',
        0,
        1,
        0,
        NULL,
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),
    (
        'tides-of-chaos-use',
        'Utilisation de Marée du chaos',
        'Utilisation disponible de Marée du chaos.',
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
        'favored-by-the-gods-use',
        'Utilisation de Favori des dieux',
        'Utilisation disponible de Favori des dieux.',
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
        'unearthly-recovery-use',
        'Utilisation de Récupération surnaturelle',
        'Utilisation disponible de Récupération surnaturelle.',
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

-- Capacités de classe et de sous-classes

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
        (
            'sorcerer-spellcasting',
            'Incantation — Ensorceleur',
            'Vous canalisez une magie innée et utilisez le Charisme comme caractéristique d’incantation.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'font-of-magic',
            'Source de magie',
            'Vous disposez de points de sorcellerie permettant notamment de créer des emplacements de sort ou d’alimenter certaines capacités.',
            'passive',
            true,
            false,
            'sorcery-points'
        ),
        (
            'metamagic',
            'Métamagie',
            'Vous apprenez à altérer vos sorts grâce à différentes options de métamagie consommant des points de sorcellerie.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'sorcerous-restoration',
            'Restauration ensorcelée',
            'Lorsque vous terminez un repos court, vous récupérez 4 points de sorcellerie.',
            'passive',
            true,
            false,
            NULL
        ),

        (
            'dragon-ancestor',
            'Ancêtre draconique',
            'Votre lignée est liée à un type de dragon dont vous choisissez l’ascendance et le type de dégâts associé.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'draconic-resilience',
            'Résilience draconique',
            'Votre maximum de points de vie augmente et votre peau écailleuse améliore votre classe d’armure lorsque vous ne portez pas d’armure.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'elemental-affinity',
            'Affinité élémentaire',
            'Les sorts infligeant les dégâts associés à votre ascendance bénéficient de votre Charisme et peuvent vous conférer temporairement une résistance.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'dragon-wings',
            'Ailes de dragon',
            'Vous pouvez déployer des ailes draconiques et obtenir une vitesse de vol.',
            'bonus_action',
            true,
            false,
            NULL
        ),
        (
            'draconic-presence',
            'Présence draconique',
            'Vous dépensez 5 points de sorcellerie pour émettre une aura de fascination ou de terreur.',
            'action',
            true,
            false,
            NULL
        ),

        (
            'wild-magic-surge',
            'Pic de magie sauvage',
            'Après certains sorts, le MJ peut demander un jet déclenchant un effet de magie sauvage.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'tides-of-chaos',
            'Marée du chaos',
            'Vous obtenez un avantage sur un jet d’attaque, de caractéristique ou de sauvegarde avant de récupérer cette utilisation.',
            'free_action',
            true,
            false,
            'tides-of-chaos-use'
        ),
        (
            'bend-luck',
            'Altération de la chance',
            'En réaction, vous dépensez 2 points de sorcellerie pour modifier le résultat du jet d’une créature proche.',
            'reaction',
            true,
            false,
            NULL
        ),
        (
            'controlled-chaos',
            'Chaos contrôlé',
            'Lorsque vous déclenchez un pic de magie sauvage, vous pouvez lancer deux résultats et choisir celui qui s’applique.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'spell-bombardment',
            'Bombardement magique',
            'Lorsque vous obtenez le maximum sur un dé de dégâts d’un sort, vous pouvez relancer un de ces dés et ajouter le nouveau résultat.',
            'passive',
            true,
            false,
            NULL
        ),

        (
            'divine-magic',
            'Magie divine',
            'Votre lien avec le divin vous donne accès aux sorts de Clerc en plus de ceux d’Ensorceleur.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'favored-by-the-gods',
            'Favori des dieux',
            'Après l’échec d’un jet d’attaque ou de sauvegarde, vous pouvez ajouter 2d4 au résultat.',
            'free_action',
            true,
            false,
            'favored-by-the-gods-use'
        ),
        (
            'empowered-healing',
            'Soin amélioré',
            'Vous pouvez dépenser 1 point de sorcellerie pour relancer certains dés d’un sort de soin proche.',
            'free_action',
            true,
            false,
            NULL
        ),
        (
            'otherworldly-wings',
            'Ailes surnaturelles',
            'Vous pouvez faire apparaître une paire d’ailes spectrales et obtenir une vitesse de vol.',
            'bonus_action',
            true,
            false,
            NULL
        ),
        (
            'unearthly-recovery',
            'Récupération surnaturelle',
            'Lorsque vous avez moins de la moitié de vos points de vie, vous pouvez récupérer la moitié de votre maximum de points de vie.',
            'bonus_action',
            true,
            false,
            'unearthly-recovery-use'
        ),

        (
            'wind-speaker',
            'Langage du vent',
            'Vous savez parler, lire et écrire le primordial et ses dialectes élémentaires.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'tempestuous-magic',
            'Magie tempétueuse',
            'Avant ou après avoir lancé un sort de niveau 1 ou supérieur, vous pouvez utiliser une action bonus pour voler brièvement sans provoquer d’attaque d’opportunité.',
            'bonus_action',
            true,
            false,
            NULL
        ),
        (
            'heart-of-the-storm',
            'Cœur de la tempête',
            'Vous obtenez une résistance à la foudre et au tonnerre et pouvez blesser les créatures proches lorsque vous lancez certains sorts.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'storm-guide',
            'Guide de la tempête',
            'Vous pouvez exercer un contrôle limité sur le vent et la pluie autour de vous.',
            'action',
            true,
            false,
            NULL
        ),
        (
            'storms-fury',
            'Fureur de la tempête',
            'Lorsqu’une créature proche vous touche, vous pouvez lui infliger des dégâts de foudre et tenter de la repousser.',
            'reaction',
            true,
            false,
            NULL
        ),
        (
            'wind-soul',
            'Âme du vent',
            'Vous devenez immunisé aux dégâts de foudre et de tonnerre et obtenez une vitesse de vol permanente.',
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
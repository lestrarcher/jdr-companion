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
    -- Moine
    (
        'ki-points',
        'Points de ki',
        'Réserve de ki du Moine, récupérée après un repos court ou long.',
        'short-rest',
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
        'wholeness-of-body-use',
        'Utilisation de Plénitude du corps',
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

    -- Rôdeur
    (
        'hunters-sense-uses',
        'Utilisations de Sens du chasseur',
        NULL,
        'long-rest',
        'ability-modifier',
        0,
        1,
        1,
        'wisdom',
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),
    (
        'magic-users-nemesis-use',
        'Utilisation de Némésis des lanceurs de sorts',
        NULL,
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

    -- Maître de guerre
    (
        'superiority-dice',
        'Dés de supériorité',
        'Dés utilisés pour alimenter les manœuvres du Maître de guerre.',
        'short-rest',
        'fixed',
        4,
        1,
        4,
        NULL,
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),

    -- Chevalier des échos
    (
        'unleash-incarnation-uses',
        'Utilisations de Déchaînement d’incarnation',
        NULL,
        'long-rest',
        'ability-modifier',
        0,
        1,
        1,
        'constitution',
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),
    (
        'shadow-martyr-use',
        'Utilisation de Martyr de l’ombre',
        NULL,
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
        'reclaim-potential-uses',
        'Utilisations de Potentiel récupéré',
        NULL,
        'long-rest',
        'ability-modifier',
        0,
        1,
        1,
        'constitution',
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),

    -- Druide
    (
        'wild-shape-uses',
        'Utilisations de Forme sauvage',
        NULL,
        'short-rest',
        'fixed',
        2,
        1,
        2,
        NULL,
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),
    (
        'spirit-totem-use',
        'Utilisation de Totem spirituel',
        NULL,
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
        'faithful-summons-use',
        'Utilisation d’Invocations fidèles',
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

    -- Barde
    (
        'bardic-inspiration-uses',
        'Utilisations d’Inspiration bardique',
        'Nombre d’utilisations égal au modificateur de Charisme, avec un minimum de 1.',
        'short-rest',
        'ability-modifier',
        0,
        1,
        1,
        'charisma',
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),
    (
        'universal-speech-use',
        'Utilisation de Discours universel',
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
        'infectious-inspiration-uses',
        'Utilisations d’Inspiration contagieuse',
        NULL,
        'long-rest',
        'ability-modifier',
        0,
        1,
        1,
        'charisma',
        false,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    ),
    (
        'enthralling-performance-use',
        'Utilisation de Représentation captivante',
        NULL,
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
        'mantle-of-majesty-use',
        'Utilisation de Manteau de majesté',
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
        'unbreakable-majesty-use',
        'Utilisation de Majesté incassable',
        NULL,
        'short-rest',
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
        -- Moine
        (
            'monk-unarmored-defense',
            'Défense sans armure — Moine',
            'Sans armure ni bouclier, votre classe d’armure utilise votre Dextérité et votre Sagesse.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'martial-arts',
            'Arts martiaux',
            'Votre entraînement vous permet d’utiliser efficacement les armes de moine et les attaques à mains nues.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'ki',
            'Ki',
            'Vous disposez de points de ki permettant d’utiliser plusieurs techniques de Moine.',
            'passive',
            true,
            false,
            'ki-points'
        ),
        (
            'flurry-of-blows',
            'Déluge de coups',
            'Après l’action Attaquer, vous dépensez 1 point de ki pour effectuer deux attaques à mains nues par une action bonus.',
            'bonus_action',
            true,
            false,
            NULL
        ),
        (
            'patient-defense',
            'Défense patiente',
            'Vous dépensez 1 point de ki pour effectuer l’action Esquiver par une action bonus.',
            'bonus_action',
            true,
            false,
            NULL
        ),
        (
            'step-of-the-wind',
            'Déplacement aérien',
            'Vous dépensez 1 point de ki pour vous Désengager ou Foncer par une action bonus et améliorer vos sauts.',
            'bonus_action',
            true,
            false,
            NULL
        ),
        (
            'unarmored-movement',
            'Déplacement sans armure',
            'Votre vitesse augmente lorsque vous ne portez ni armure ni bouclier.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'deflect-missiles',
            'Parade de projectiles',
            'Vous utilisez votre réaction pour réduire les dégâts d’une attaque à distance et pouvez dépenser 1 point de ki pour renvoyer le projectile.',
            'reaction',
            true,
            false,
            NULL
        ),
        (
            'slow-fall',
            'Chute ralentie',
            'Vous utilisez votre réaction pour réduire les dégâts que vous subissez lors d’une chute.',
            'reaction',
            true,
            false,
            NULL
        ),
        (
            'extra-attack-monk',
            'Attaque supplémentaire — Moine',
            'Vous pouvez attaquer deux fois lorsque vous entreprenez l’action Attaquer.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'stunning-strike',
            'Frappe étourdissante',
            'Après avoir touché avec une attaque de corps à corps, vous dépensez 1 point de ki pour tenter d’étourdir la cible.',
            'free_action',
            true,
            false,
            NULL
        ),
        (
            'ki-empowered-strikes',
            'Frappes de ki',
            'Vos attaques à mains nues sont considérées comme magiques pour surmonter résistances et immunités.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'evasion-monk',
            'Dérobade — Moine',
            'Votre agilité vous permet de réduire ou d’éviter certains effets demandant un jet de sauvegarde de Dextérité.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'stillness-of-mind',
            'Tranquillité de l’esprit',
            'Vous pouvez utiliser votre action pour mettre fin à un effet vous charmant ou vous effrayant.',
            'action',
            true,
            false,
            NULL
        ),
        (
            'purity-of-body',
            'Pureté du corps',
            'Votre maîtrise du ki vous immunise contre les maladies et le poison.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'tongue-of-the-sun-and-moon',
            'Langage du soleil et de la lune',
            'Vous comprenez toutes les langues parlées et pouvez être compris par toute créature connaissant une langue.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'diamond-soul',
            'Âme de diamant',
            'Vous maîtrisez tous les jets de sauvegarde et pouvez dépenser 1 point de ki pour relancer un échec.',
            'free_action',
            true,
            false,
            NULL
        ),
        (
            'timeless-body-monk',
            'Jeunesse éternelle — Moine',
            'Votre ki ralentit les effets du vieillissement et vous dispense de nourriture et d’eau.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'empty-body',
            'Désertion de l’âme',
            'Vous dépensez du ki pour devenir invisible et résistant à presque tous les dégâts, ou pour lancer Projection astrale.',
            'action',
            true,
            false,
            NULL
        ),
        (
            'perfect-self',
            'Perfection de l’être',
            'Lorsque vous lancez l’initiative sans ki disponible, vous récupérez 4 points de ki.',
            'passive',
            true,
            false,
            NULL
        ),

        -- Voie de la Main ouverte
        (
            'open-hand-technique',
            'Technique de la Main ouverte',
            'Lorsque vous utilisez Déluge de coups, vous pouvez déséquilibrer, repousser ou empêcher la réaction d’une cible.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'wholeness-of-body',
            'Plénitude du corps',
            'Vous récupérez trois fois votre niveau de Moine en points de vie.',
            'action',
            true,
            false,
            'wholeness-of-body-use'
        ),
        (
            'tranquility',
            'Tranquillité',
            'Après un repos long, vous bénéficiez d’un effet protecteur semblable au sort Sanctuaire.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'quivering-palm',
            'Paume vibratoire',
            'Vous dépensez 3 points de ki pour placer des vibrations mortelles dans le corps d’une créature.',
            'action',
            true,
            false,
            NULL
        ),

        -- Voie de l’Ombre
        (
            'shadow-arts',
            'Arts de l’ombre',
            'Vous utilisez votre ki pour reproduire plusieurs effets magiques liés aux ténèbres et au silence.',
            'action',
            true,
            false,
            NULL
        ),
        (
            'shadow-step',
            'Pas de l’ombre',
            'Depuis une zone de lumière faible ou de ténèbres, vous vous téléportez vers une autre zone ombragée.',
            'bonus_action',
            true,
            false,
            NULL
        ),
        (
            'cloak-of-shadows',
            'Cape d’ombres',
            'Dans une zone de lumière faible ou de ténèbres, vous pouvez devenir invisible.',
            'action',
            true,
            false,
            NULL
        ),
        (
            'opportunist',
            'Opportuniste',
            'Lorsqu’une créature proche est touchée par une autre créature, vous pouvez l’attaquer avec votre réaction.',
            'reaction',
            true,
            false,
            NULL
        ),

        -- Rôdeur
        (
            'favored-enemy',
            'Ennemi juré',
            'Vous choisissez des types de créatures que vous savez mieux traquer et dont vous connaissez les habitudes.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'natural-explorer',
            'Explorateur-né',
            'Vous choisissez des environnements dans lesquels votre expérience améliore vos déplacements et votre survie.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'ranger-fighting-style',
            'Style de combat — Rôdeur',
            'Vous adoptez un style de combat spécialisé.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'ranger-spellcasting',
            'Incantation — Rôdeur',
            'Vous lancez des sorts de Rôdeur en utilisant la Sagesse.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'primeval-awareness',
            'Vigilance primitive',
            'Vous dépensez un emplacement de sort pour détecter certains types de créatures dans la région.',
            'action',
            true,
            false,
            NULL
        ),
        (
            'extra-attack-ranger',
            'Attaque supplémentaire — Rôdeur',
            'Vous pouvez attaquer deux fois lorsque vous entreprenez l’action Attaquer.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'lands-stride',
            'Traversée des terrains',
            'Vous vous déplacez plus facilement à travers les terrains difficiles et la végétation.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'hide-in-plain-sight',
            'Camouflage naturel',
            'Vous pouvez préparer un camouflage élaboré afin de mieux vous dissimuler.',
            'action',
            true,
            false,
            NULL
        ),
        (
            'vanish',
            'Disparition',
            'Vous pouvez vous Cacher par une action bonus et devenez plus difficile à suivre.',
            'bonus_action',
            true,
            false,
            NULL
        ),
        (
            'feral-senses',
            'Sens sauvages',
            'Vous combattez plus efficacement les créatures que vous ne pouvez pas voir.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'foe-slayer',
            'Tueur d’ennemis',
            'Vous ajoutez votre modificateur de Sagesse à certaines attaques ou à leurs dégâts contre vos ennemis jurés.',
            'free_action',
            true,
            false,
            NULL
        ),

        -- Tueur de monstres
        (
            'monster-slayer-magic',
            'Magie du Tueur de monstres',
            'Vous apprenez des sorts supplémentaires adaptés à la traque des créatures surnaturelles.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'hunters-sense',
            'Sens du chasseur',
            'Vous analysez une créature afin de découvrir ses vulnérabilités, résistances et immunités.',
            'action',
            true,
            false,
            'hunters-sense-uses'
        ),
        (
            'slayers-prey',
            'Proie du chasseur',
            'Vous désignez une créature et lui infligez des dégâts supplémentaires une fois par tour.',
            'bonus_action',
            true,
            false,
            NULL
        ),
        (
            'supernatural-defense',
            'Défense surnaturelle',
            'Vous ajoutez un dé à certaines sauvegardes et tentatives d’évasion contre votre Proie du chasseur.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'magic-users-nemesis',
            'Némésis des lanceurs de sorts',
            'Vous utilisez votre réaction pour tenter d’empêcher une téléportation ou un sort lancé par une créature proche.',
            'reaction',
            true,
            false,
            'magic-users-nemesis-use'
        ),
        (
            'slayers-counter',
            'Contre du chasseur',
            'Lorsqu’une Proie du chasseur vous force à effectuer une sauvegarde, vous pouvez effectuer une attaque contre elle.',
            'reaction',
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
        -- Champion
        (
            'improved-critical',
            'Critique amélioré',
            'Vos attaques avec une arme réalisent un coup critique sur un résultat naturel de 19 ou 20.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'remarkable-athlete',
            'Athlète remarquable',
            'Vous ajoutez une partie de votre bonus de maîtrise aux tests physiques qui ne le comprennent pas déjà.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'additional-fighting-style',
            'Style de combat supplémentaire',
            'Vous choisissez un second style de combat.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'superior-critical',
            'Critique supérieur',
            'Vos attaques avec une arme réalisent un coup critique sur un résultat naturel de 18 à 20.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'survivor',
            'Survivant',
            'Au début de chacun de vos tours, vous récupérez des points de vie lorsque vous êtes sous la moitié de votre maximum.',
            'passive',
            true,
            false,
            NULL
        ),

        -- Maître de guerre
        (
            'combat-superiority',
            'Supériorité au combat',
            'Vous apprenez des manœuvres alimentées par vos dés de supériorité.',
            'passive',
            true,
            false,
            'superiority-dice'
        ),
        (
            'battle-master-maneuvers',
            'Manœuvres',
            'Vous choisissez plusieurs manœuvres de Maître de guerre.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'student-of-war',
            'Étudiant de la guerre',
            'Vous obtenez la maîtrise d’un type d’outils d’artisan.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'know-your-enemy',
            'Connaître son ennemi',
            'Après avoir observé une créature, vous obtenez des indications sur certaines de ses capacités.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'improved-combat-superiority-d10',
            'Supériorité au combat améliorée — d10',
            'Vos dés de supériorité deviennent des d10.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'relentless-battle-master',
            'Implacable — Maître de guerre',
            'Lorsque vous lancez l’initiative sans dé de supériorité, vous en récupérez un.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'improved-combat-superiority-d12',
            'Supériorité au combat améliorée — d12',
            'Vos dés de supériorité deviennent des d12.',
            'passive',
            true,
            false,
            NULL
        ),

        -- Chevalier des échos
        (
            'manifest-echo',
            'Manifestation d’écho',
            'Vous manifestez un écho magique de vous-même que vous pouvez déplacer et utiliser pour attaquer ou échanger votre position.',
            'bonus_action',
            true,
            false,
            NULL
        ),
        (
            'unleash-incarnation',
            'Déchaînement d’incarnation',
            'Lorsque vous attaquez, vous effectuez une attaque supplémentaire depuis la position de votre écho.',
            'free_action',
            true,
            false,
            'unleash-incarnation-uses'
        ),
        (
            'echo-avatar',
            'Avatar d’écho',
            'Vous transférez temporairement votre conscience dans votre écho afin de voir et entendre à travers lui.',
            'action',
            true,
            false,
            NULL
        ),
        (
            'shadow-martyr',
            'Martyr de l’ombre',
            'Vous téléportez votre écho devant une créature afin qu’il subisse une attaque à sa place.',
            'reaction',
            true,
            false,
            'shadow-martyr-use'
        ),
        (
            'reclaim-potential',
            'Potentiel récupéré',
            'Lorsque votre écho est détruit, vous pouvez gagner des points de vie temporaires.',
            'reaction',
            true,
            false,
            'reclaim-potential-uses'
        ),
        (
            'legion-of-one',
            'Légion d’un seul',
            'Vous pouvez maintenir deux échos et récupérer une utilisation de Déchaînement d’incarnation en lançant l’initiative.',
            'passive',
            true,
            false,
            NULL
        ),

        -- Roublard
        (
            'rogue-expertise',
            'Expertise — Roublard',
            'Vous doublez votre bonus de maîtrise pour certaines compétences ou vos outils de voleur.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'sneak-attack',
            'Attaque sournoise',
            'Une fois par tour, vous infligez des dégâts supplémentaires lorsque les conditions d’Attaque sournoise sont réunies.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'thieves-cant',
            'Argot des voleurs',
            'Vous connaissez le langage codé utilisé par les criminels et les voleurs.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'cunning-action',
            'Ruse',
            'Vous pouvez Foncer, vous Désengager ou vous Cacher par une action bonus.',
            'bonus_action',
            true,
            false,
            NULL
        ),
        (
            'uncanny-dodge',
            'Esquive instinctive',
            'Vous utilisez votre réaction pour réduire de moitié les dégâts d’une attaque visible.',
            'reaction',
            true,
            false,
            NULL
        ),
        (
            'evasion-rogue',
            'Dérobade — Roublard',
            'Vous réduisez ou évitez certains dégâts demandant un jet de sauvegarde de Dextérité.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'reliable-talent',
            'Talent fiable',
            'Certains faibles résultats obtenus sur vos tests maîtrisés sont considérés comme un 10.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'blindsense',
            'Perception aveugle',
            'Vous connaissez la position des créatures cachées ou invisibles suffisamment proches.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'slippery-mind',
            'Esprit fuyant',
            'Vous obtenez la maîtrise des jets de sauvegarde de Sagesse.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'elusive',
            'Insaisissable',
            'Aucune attaque ne bénéficie d’un avantage contre vous tant que vous n’êtes pas incapable d’agir.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'stroke-of-luck',
            'Coup de chance',
            'Vous pouvez transformer une attaque manquée ou un test raté en réussite.',
            'free_action',
            true,
            false,
            NULL
        ),

        -- Assassin
        (
            'assassin-bonus-proficiencies',
            'Maîtrises supplémentaires — Assassin',
            'Vous maîtrisez les accessoires de déguisement et le matériel d’empoisonneur.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'assassinate',
            'Assassinat',
            'Vous êtes particulièrement dangereux contre les créatures qui n’ont pas encore agi ou qui sont surprises.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'infiltration-expertise',
            'Expert en infiltration',
            'Vous pouvez établir une fausse identité complète et crédible.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'impostor',
            'Imposteur',
            'Vous pouvez imiter le comportement, la voix et l’écriture d’une autre personne.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'death-strike',
            'Frappe mortelle',
            'Vos attaques contre une créature surprise peuvent doubler leurs dégâts.',
            'passive',
            true,
            false,
            NULL
        ),

        -- Druide
        (
            'druidic',
            'Druidique',
            'Vous connaissez le langage secret des druides.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'druid-spellcasting',
            'Incantation — Druide',
            'Vous préparez et lancez des sorts de Druide en utilisant la Sagesse.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'wild-shape',
            'Forme sauvage',
            'Vous prenez magiquement la forme d’une bête que vous avez déjà vue.',
            'action',
            true,
            false,
            'wild-shape-uses'
        ),
        (
            'wild-shape-improvement',
            'Amélioration de Forme sauvage',
            'Les formes accessibles et les possibilités de déplacement de votre Forme sauvage progressent avec votre niveau.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'timeless-body-druid',
            'Jeunesse éternelle — Druide',
            'Votre magie primordiale ralentit considérablement votre vieillissement.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'beast-spells',
            'Sorts de bête',
            'Vous pouvez lancer de nombreux sorts de Druide lorsque vous êtes sous Forme sauvage.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'archdruid',
            'Archidruide',
            'Vous pouvez utiliser Forme sauvage sans limitation et ignorer plusieurs composantes de sorts.',
            'passive',
            true,
            false,
            NULL
        ),

        -- Cercle des Bergers
        (
            'speech-of-the-woods',
            'Langage des bois',
            'Vous apprenez le sylvestre et pouvez communiquer avec les bêtes.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'spirit-totem',
            'Totem spirituel',
            'Vous invoquez un esprit incorporel prenant la forme de l’Ours, du Faucon ou de la Licorne.',
            'bonus_action',
            true,
            false,
            'spirit-totem-use'
        ),
        (
            'mighty-summoner',
            'Puissant invocateur',
            'Les bêtes et fées que vous invoquez deviennent plus résistantes et leurs attaques sont considérées comme magiques.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'guardian-spirit',
            'Esprit gardien',
            'Votre Totem spirituel soigne les bêtes et fées invoquées qui terminent leur tour dans son aura.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'faithful-summons',
            'Invocations fidèles',
            'Lorsque vous tombez à 0 point de vie, des esprits animaux apparaissent pour vous protéger.',
            'passive',
            true,
            false,
            'faithful-summons-use'
        ),

        -- Barde
        (
            'bard-spellcasting',
            'Incantation — Barde',
            'Vous lancez des sorts de Barde en utilisant le Charisme.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'bardic-inspiration',
            'Inspiration bardique',
            'Vous accordez un dé d’inspiration à une créature afin d’améliorer l’un de ses jets.',
            'bonus_action',
            true,
            false,
            'bardic-inspiration-uses'
        ),
        (
            'jack-of-all-trades',
            'Touche-à-tout',
            'Vous ajoutez la moitié de votre bonus de maîtrise aux tests qui ne l’utilisent pas déjà.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'song-of-rest',
            'Chant reposant',
            'Vos alliés récupèrent davantage de points de vie pendant un repos court.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'bard-expertise',
            'Expertise — Barde',
            'Vous doublez votre bonus de maîtrise pour certaines compétences.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'font-of-inspiration',
            'Source d’inspiration',
            'Vos utilisations d’Inspiration bardique se récupèrent après un repos court ou long.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'countercharm',
            'Contre-charme',
            'Votre représentation protège vos alliés contre les effets de charme et de terreur.',
            'action',
            true,
            false,
            NULL
        ),
        (
            'magical-secrets',
            'Secrets magiques',
            'Vous apprenez des sorts provenant de n’importe quelle classe.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'superior-inspiration',
            'Inspiration supérieure',
            'Lorsque vous lancez l’initiative sans Inspiration bardique, vous en récupérez une utilisation.',
            'passive',
            true,
            false,
            NULL
        ),

        -- Collège de l’Éloquence
        (
            'silver-tongue',
            'Langue d’argent',
            'Vos faibles résultats aux tests de Persuasion et de Tromperie sont considérés comme un 10.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'unsettling-words',
            'Paroles déstabilisantes',
            'Vous dépensez une Inspiration bardique pour réduire le prochain jet de sauvegarde d’une créature.',
            'bonus_action',
            true,
            false,
            NULL
        ),
        (
            'unfailing-inspiration',
            'Inspiration infaillible',
            'Une créature qui échoue malgré votre Inspiration bardique conserve son dé.',
            'passive',
            true,
            false,
            NULL
        ),
        (
            'universal-speech',
            'Discours universel',
            'Vous permettez temporairement à plusieurs créatures de comprendre vos paroles.',
            'action',
            true,
            false,
            'universal-speech-use'
        ),
        (
            'infectious-inspiration',
            'Inspiration contagieuse',
            'Lorsqu’une Inspiration bardique permet une réussite, vous pouvez inspirer une autre créature.',
            'reaction',
            true,
            false,
            'infectious-inspiration-uses'
        ),

        -- Collège de la Séduction
        (
            'mantle-of-inspiration',
            'Manteau d’inspiration',
            'Vous dépensez une Inspiration bardique pour accorder des points de vie temporaires et permettre des déplacements.',
            'bonus_action',
            true,
            false,
            NULL
        ),
        (
            'enthralling-performance',
            'Représentation captivante',
            'Après une représentation, vous pouvez charmer plusieurs humanoïdes qui vous ont observé.',
            'action',
            true,
            false,
            'enthralling-performance-use'
        ),
        (
            'mantle-of-majesty',
            'Manteau de majesté',
            'Vous adoptez une apparence surnaturelle et pouvez lancer Ordre à chacun de vos tours.',
            'bonus_action',
            true,
            false,
            'mantle-of-majesty-use'
        ),
        (
            'unbreakable-majesty',
            'Majesté incassable',
            'Votre présence surnaturelle rend les attaques contre vous plus difficiles.',
            'bonus_action',
            true,
            false,
            'unbreakable-majesty-use'
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

    -- Capacités générales des classes

WITH class_rules (
    class_slug,
    feature_slug,
    unlock_level,
    display_order
) AS (
    VALUES
        -- Moine
        ('monk', 'monk-unarmored-defense', 1, 10),
        ('monk', 'martial-arts', 1, 20),
        ('monk', 'ki', 2, 30),
        ('monk', 'flurry-of-blows', 2, 40),
        ('monk', 'patient-defense', 2, 50),
        ('monk', 'step-of-the-wind', 2, 60),
        ('monk', 'unarmored-movement', 2, 70),
        ('monk', 'deflect-missiles', 3, 80),
        ('monk', 'slow-fall', 4, 90),
        ('monk', 'extra-attack-monk', 5, 100),
        ('monk', 'stunning-strike', 5, 110),
        ('monk', 'ki-empowered-strikes', 6, 120),
        ('monk', 'evasion-monk', 7, 130),
        ('monk', 'stillness-of-mind', 7, 140),
        ('monk', 'purity-of-body', 10, 150),
        ('monk', 'tongue-of-the-sun-and-moon', 13, 160),
        ('monk', 'diamond-soul', 14, 170),
        ('monk', 'timeless-body-monk', 15, 180),
        ('monk', 'empty-body', 18, 190),
        ('monk', 'perfect-self', 20, 200),

        -- Rôdeur
        ('rodeur', 'favored-enemy', 1, 10),
        ('rodeur', 'natural-explorer', 1, 20),
        ('rodeur', 'ranger-fighting-style', 2, 30),
        ('rodeur', 'ranger-spellcasting', 2, 40),
        ('rodeur', 'primeval-awareness', 3, 50),
        ('rodeur', 'extra-attack-ranger', 5, 60),
        ('rodeur', 'lands-stride', 8, 70),
        ('rodeur', 'hide-in-plain-sight', 10, 80),
        ('rodeur', 'vanish', 14, 90),
        ('rodeur', 'feral-senses', 18, 100),
        ('rodeur', 'foe-slayer', 20, 110),

        -- Roublard
        ('rogue', 'rogue-expertise', 1, 10),
        ('rogue', 'sneak-attack', 1, 20),
        ('rogue', 'thieves-cant', 1, 30),
        ('rogue', 'cunning-action', 2, 40),
        ('rogue', 'uncanny-dodge', 5, 50),
        ('rogue', 'rogue-expertise', 6, 60),
        ('rogue', 'evasion-rogue', 7, 70),
        ('rogue', 'reliable-talent', 11, 80),
        ('rogue', 'blindsense', 14, 90),
        ('rogue', 'slippery-mind', 15, 100),
        ('rogue', 'elusive', 18, 110),
        ('rogue', 'stroke-of-luck', 20, 120),

        -- Druide
        ('druide', 'druidic', 1, 10),
        ('druide', 'druid-spellcasting', 1, 20),
        ('druide', 'wild-shape', 2, 30),
        ('druide', 'wild-shape-improvement', 4, 40),
        ('druide', 'wild-shape-improvement', 8, 50),
        ('druide', 'timeless-body-druid', 18, 60),
        ('druide', 'beast-spells', 18, 70),
        ('druide', 'archdruid', 20, 80),

        -- Barde
        ('barde', 'bard-spellcasting', 1, 10),
        ('barde', 'bardic-inspiration', 1, 20),
        ('barde', 'jack-of-all-trades', 2, 30),
        ('barde', 'song-of-rest', 2, 40),
        ('barde', 'bard-expertise', 3, 50),
        ('barde', 'font-of-inspiration', 5, 60),
        ('barde', 'countercharm', 6, 70),
        ('barde', 'bard-expertise', 10, 80),
        ('barde', 'magical-secrets', 10, 90),
        ('barde', 'magical-secrets', 14, 100),
        ('barde', 'magical-secrets', 18, 110),
        ('barde', 'superior-inspiration', 20, 120)
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
    ON character_class.slug = rules.class_slug
INNER JOIN character_feature_definition feature
    ON feature.slug = rules.feature_slug
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
        -- Main ouverte
        ('monk', 'open-hand', 'open-hand-technique', 3, 30),
        ('monk', 'open-hand', 'wholeness-of-body', 6, 60),
        ('monk', 'open-hand', 'tranquility', 11, 110),
        ('monk', 'open-hand', 'quivering-palm', 17, 170),

        -- Ombre
        ('monk', 'shadow', 'shadow-arts', 3, 30),
        ('monk', 'shadow', 'shadow-step', 6, 60),
        ('monk', 'shadow', 'cloak-of-shadows', 11, 110),
        ('monk', 'shadow', 'opportunist', 17, 170),

        -- Tueur de monstres
        ('rodeur', 'monster-slayer', 'monster-slayer-magic', 3, 30),
        ('rodeur', 'monster-slayer', 'hunters-sense', 3, 40),
        ('rodeur', 'monster-slayer', 'slayers-prey', 3, 50),
        ('rodeur', 'monster-slayer', 'supernatural-defense', 7, 70),
        ('rodeur', 'monster-slayer', 'magic-users-nemesis', 11, 110),
        ('rodeur', 'monster-slayer', 'slayers-counter', 15, 150),

        -- Champion
        ('fighter', 'champion', 'improved-critical', 3, 30),
        ('fighter', 'champion', 'remarkable-athlete', 7, 70),
        ('fighter', 'champion', 'additional-fighting-style', 10, 100),
        ('fighter', 'champion', 'superior-critical', 15, 150),
        ('fighter', 'champion', 'survivor', 18, 180),

        -- Maître de guerre
        ('fighter', 'battle-master', 'combat-superiority', 3, 30),
        ('fighter', 'battle-master', 'battle-master-maneuvers', 3, 40),
        ('fighter', 'battle-master', 'student-of-war', 3, 50),
        ('fighter', 'battle-master', 'know-your-enemy', 7, 70),
        (
            'fighter',
            'battle-master',
            'improved-combat-superiority-d10',
            10,
            100
        ),
        ('fighter', 'battle-master', 'relentless-battle-master', 15, 150),
        (
            'fighter',
            'battle-master',
            'improved-combat-superiority-d12',
            18,
            180
        ),

        -- Chevalier des échos
        ('fighter', 'echo-knight', 'manifest-echo', 3, 30),
        ('fighter', 'echo-knight', 'unleash-incarnation', 3, 40),
        ('fighter', 'echo-knight', 'echo-avatar', 7, 70),
        ('fighter', 'echo-knight', 'shadow-martyr', 10, 100),
        ('fighter', 'echo-knight', 'reclaim-potential', 15, 150),
        ('fighter', 'echo-knight', 'legion-of-one', 18, 180),

        -- Assassin
        ('rogue', 'assassin', 'assassin-bonus-proficiencies', 3, 30),
        ('rogue', 'assassin', 'assassinate', 3, 40),
        ('rogue', 'assassin', 'infiltration-expertise', 9, 90),
        ('rogue', 'assassin', 'impostor', 13, 130),
        ('rogue', 'assassin', 'death-strike', 17, 170),

        -- Cercle des Bergers
        ('druide', 'circle-of-the-shepherd', 'speech-of-the-woods', 2, 20),
        ('druide', 'circle-of-the-shepherd', 'spirit-totem', 2, 30),
        ('druide', 'circle-of-the-shepherd', 'mighty-summoner', 6, 60),
        ('druide', 'circle-of-the-shepherd', 'guardian-spirit', 10, 100),
        ('druide', 'circle-of-the-shepherd', 'faithful-summons', 14, 140),

        -- Collège de l’Éloquence
        ('barde', 'college-of-eloquence', 'silver-tongue', 3, 30),
        ('barde', 'college-of-eloquence', 'unsettling-words', 3, 40),
        ('barde', 'college-of-eloquence', 'unfailing-inspiration', 6, 60),
        ('barde', 'college-of-eloquence', 'universal-speech', 6, 70),
        ('barde', 'college-of-eloquence', 'infectious-inspiration', 14, 140),

        -- Collège de la Séduction
        ('barde', 'college-of-glamour', 'mantle-of-inspiration', 3, 30),
        ('barde', 'college-of-glamour', 'enthralling-performance', 3, 40),
        ('barde', 'college-of-glamour', 'mantle-of-majesty', 6, 60),
        ('barde', 'college-of-glamour', 'unbreakable-majesty', 14, 140)
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
    ON character_class.slug = rules.class_slug
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

-- Progression des points de ki

WITH ki_progression (
    unlock_level,
    maximum_override
) AS (
    VALUES
        (2, 2), (3, 3), (4, 4), (5, 5), (6, 6),
        (7, 7), (8, 8), (9, 9), (10, 10), (11, 11),
        (12, 12), (13, 13), (14, 14), (15, 15),
        (16, 16), (17, 17), (18, 18), (19, 19), (20, 20)
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
FROM ki_progression progression
INNER JOIN character_class
    ON character_class.slug = 'monk'
INNER JOIN trackable_resource_definition resource
    ON resource.slug = 'ki-points'
ON CONFLICT (
    character_class_id,
    resource_definition_id,
    unlock_level
)
DO UPDATE SET
    maximum_override = EXCLUDED.maximum_override;

-- Ressources générales des classes

WITH class_resource_rules (
    class_slug,
    resource_slug,
    unlock_level,
    maximum_override
) AS (
    VALUES
        ('druide', 'wild-shape-uses', 2, 2),
        ('barde', 'bardic-inspiration-uses', 1, NULL)
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
    ON character_class.slug = rules.class_slug
INNER JOIN trackable_resource_definition resource
    ON resource.slug = rules.resource_slug
ON CONFLICT (
    character_class_id,
    resource_definition_id,
    unlock_level
)
DO UPDATE SET
    maximum_override = EXCLUDED.maximum_override;

-- Ressources des sous-classes

WITH subclass_resource_rules (
    class_slug,
    subclass_slug,
    resource_slug,
    unlock_level,
    maximum_override
) AS (
    VALUES
        ('monk', 'open-hand', 'wholeness-of-body-use', 6, 1),

        ('rodeur', 'monster-slayer', 'hunters-sense-uses', 3, NULL),
        (
            'rodeur',
            'monster-slayer',
            'magic-users-nemesis-use',
            11,
            1
        ),

        ('fighter', 'battle-master', 'superiority-dice', 3, 4),
        ('fighter', 'battle-master', 'superiority-dice', 7, 5),
        ('fighter', 'battle-master', 'superiority-dice', 15, 6),

        (
            'fighter',
            'echo-knight',
            'unleash-incarnation-uses',
            3,
            NULL
        ),
        ('fighter', 'echo-knight', 'shadow-martyr-use', 10, 1),
        (
            'fighter',
            'echo-knight',
            'reclaim-potential-uses',
            15,
            NULL
        ),

        ('druide', 'circle-of-the-shepherd', 'spirit-totem-use', 2, 1),
        (
            'druide',
            'circle-of-the-shepherd',
            'faithful-summons-use',
            14,
            1
        ),

        ('barde', 'college-of-eloquence', 'universal-speech-use', 6, 1),
        (
            'barde',
            'college-of-eloquence',
            'infectious-inspiration-uses',
            14,
            NULL
        ),

        (
            'barde',
            'college-of-glamour',
            'enthralling-performance-use',
            3,
            1
        ),
        (
            'barde',
            'college-of-glamour',
            'mantle-of-majesty-use',
            6,
            1
        ),
        (
            'barde',
            'college-of-glamour',
            'unbreakable-majesty-use',
            14,
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
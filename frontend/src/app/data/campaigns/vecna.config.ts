import { CampaignConfig } from '@core/models/campaign.model';
import {
  STRAHD_MOON_PHASES,
  STRAHD_WEATHER_STATES,
} from '@data/campaigns/strahd.config';

export const VECNA_CAMPAIGN: CampaignConfig = {
  id: 'campaign-vecna-template',
  name: 'Vecna : au seuil du néant',
  worldName: 'Plan matériel',

  moonPhases: STRAHD_MOON_PHASES,
  weatherStates: STRAHD_WEATHER_STATES,

  theme: {
    backgroundImage:
      '/assets/backgrounds/background-vecna.png',

    colors: {
      primary: '#d8d2e8',
      accent: '#9276c8',
      text: '#f4f0fa',
    },

    effects: {
      fog: false,
    },
  },

  players: [
    {
      id: 'player-frank',
      displayName: 'Quentin',
      characterId: 'character-frank',
      accessToken: 'demo-frank',
    },
    {
      id: 'player-eneis',
      displayName: 'Mouss',
      characterId: 'character-eneis',
      accessToken: 'demo-eneis',
    },
    {
      id: 'player-riven',
      displayName: 'Joce',
      characterId: 'character-riven',
      accessToken: 'demo-riven',
    },
    {
      id: 'player-jakharis',
      displayName: 'Mélanie',
      characterId: 'character-jakharis',
      accessToken: 'demo-jakharis',
    },
    {
      id: 'player-rohunar',
      displayName: 'Marina',
      characterId: 'character-rohunar',
      accessToken: 'demo-rohunar',
    },
    {
      id: 'player-azriel',
      displayName: 'Marina',
      characterId: 'character-azriel',
      accessToken: 'demo-azriel',
    },
    {
      id: 'player-anoukis',
      displayName: 'Mouss',
      characterId: 'character-anoukis',
      accessToken: 'demo-anoukis',
    },
  ],

  characters: [
    {
      id: 'character-frank',
      name: 'Frank',
      type: 'pc',

      className:
        'Occultiste du Dao 2 / Ensorceleur draconique 11',
      level: 13,

      hitPoints: {
        current: 80,
        maximum: 106,
        temporary: 0,
      },

      hitDice: [
        {
          id: 'frank-warlock-hit-dice',
          die: 'd8',
          current: 2,
          maximum: 2,
        },
        {
          id: 'frank-sorcerer-hit-dice',
          die: 'd6',
          current: 11,
          maximum: 11,
        },
      ],

      progressions: [
        {
          id: 'frank-fungal-infestation',
          name: 'Points d’infestation fongique',
          currentValue: 5,
          minimumValue: 1,
          accentColor: '#738b56',

          states: [
            {
              label: 'Infection fongique',
              minimumValue: 1,
              maximumValue: 7,
              iconUrl:
                '/assets/status/progressions/frank/01-infection-fongique.png',
            },
            {
              label: 'Fracture de l’esprit',
              minimumValue: 8,
              maximumValue: 14,
              iconUrl:
                '/assets/status/progressions/frank/02-fracture-esprit.png',
            },
            {
              label: 'Mycélium rampant',
              minimumValue: 15,
              maximumValue: 21,
              iconUrl:
                '/assets/status/progressions/frank/03-mycelium-rampant.png',
            },
            {
              label: 'Étreinte de la guenaude',
              minimumValue: 22,
              maximumValue: 28,
              iconUrl:
                '/assets/status/progressions/frank/04-etreinte-guenaude.png',
            },
            {
              label: 'Agent contagieux',
              minimumValue: 29,
              maximumValue: 34,
              iconUrl:
                '/assets/status/progressions/frank/05-agent-contagieux.png',
            },
            {
              label: 'Marionnette de Venlee',
              minimumValue: 35,
              iconUrl:
                '/assets/status/progressions/frank/06-marionnette-venlee.png',
            },
          ],
        },
      ],

      resources: [
        {
          id: 'frank-bottled-respite',
          name: 'Répit embouteillé',
          shortName: 'Répit embouteillé',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 10,
        },
        {
          id: 'frank-sorcery-points',
          name: 'Points de sorcellerie',
          shortName: 'Points de sorcellerie',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 11,
          maximumValue: 11,
          displayOrder: 20,
        },
        {
          id: 'frank-detect-invisibility',
          name: 'Détection de l’invisibilité',
          shortName: 'Détection de l’invisibilité',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 30,
        },
        {
          id: 'frank-past-life-knowledge',
          name: 'Savoir d’une vie antérieure',
          shortName: 'Savoir antérieur',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 5,
          maximumValue: 5,
          displayOrder: 40,
        },
        {
          id: 'frank-symbiotic-entity',
          name: 'Entité symbiotique',
          shortName: 'Entité symbiotique',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 2,
          maximumValue: 2,
          displayOrder: 50,

          unlockCondition: {
            progressionId:
              'frank-fungal-infestation',
            minimumValue: 15,
          },
        },
        {
          id: 'frank-warlock-spell-slot-1',
          name: 'Emplacements d’occultiste de niveau 1',
          shortName: 'Sorts occultiste N1',
          category: 'spell-slot',
          resetPeriod: 'short-rest',
          currentValue: 2,
          maximumValue: 2,
          level: 1,
          displayOrder: 60,
        },
        {
          id: 'frank-sorcerer-spell-slot-1',
          name: 'Emplacements d’ensorceleur de niveau 1',
          shortName: 'Sorts ensorceleur N1',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 4,
          maximumValue: 4,
          level: 1,
          displayOrder: 70,
        },
        {
          id: 'frank-spell-slot-2',
          name: 'Emplacements de sorts de niveau 2',
          shortName: 'Sorts niveau 2',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 3,
          maximumValue: 3,
          level: 2,
          displayOrder: 80,
        },
        {
          id: 'frank-spell-slot-3',
          name: 'Emplacements de sorts de niveau 3',
          shortName: 'Sorts niveau 3',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 3,
          maximumValue: 3,
          level: 3,
          displayOrder: 90,
        },
        {
          id: 'frank-spell-slot-4',
          name: 'Emplacements de sorts de niveau 4',
          shortName: 'Sorts niveau 4',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 3,
          maximumValue: 3,
          level: 4,
          displayOrder: 100,
        },
        {
          id: 'frank-spell-slot-5',
          name: 'Emplacements de sorts de niveau 5',
          shortName: 'Sorts niveau 5',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 2,
          maximumValue: 2,
          level: 5,
          displayOrder: 110,
        },
        {
          id: 'frank-spell-slot-6',
          name: 'Emplacement de sort de niveau 6',
          shortName: 'Sort niveau 6',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          level: 6,
          displayOrder: 120,
        },
      ],
    },

    {
      id: 'character-eneis',
      name: 'Énéis',
      type: 'pc',

      className:
        'Paladine du serment de Vengeance',
      level: 13,

      hitPoints: {
        current: 7,
        maximum: 106,
        temporary: 0,
      },

      hitDice: [
        {
          id: 'eneis-hit-dice',
          die: 'd10',
          current: 13,
          maximum: 13,
        },
      ],

      progressions: [
        {
          id: 'eneis-electric-charges',
          name: 'Charges électriques',
          currentValue: 76,
          minimumValue: 0,
          accentColor: '#4dbfff',

          states: [
            {
              label: 'Vulnérabilité à la foudre',
              minimumValue: 0,
              maximumValue: 24,
              iconUrl:
                '/assets/status/electric/lightning-vulnerability.png',
            },
            {
              label: 'Résistance à la foudre',
              minimumValue: 50,
              maximumValue: 74,
              iconUrl:
                '/assets/status/electric/lightning-resistance.png',
            },
            {
              label: 'Immunité à la foudre',
              minimumValue: 75,
              maximumValue: 149,
              iconUrl:
                '/assets/status/electric/lightning-immunity.png',
            },
            {
              label: 'Surcharge électrique',
              minimumValue: 150,
              iconUrl:
                '/assets/status/electric/lightning-overload.png',
            },
          ],

          bulkAdjustment: {
            gainLabel: 'Absorption',
            spendLabel: 'Dépense',
          },

          linkedResource: {
            resourceId: 'eneis-lightning-step',
            label: 'Déplacement éclair',
          },
        },
      ],

      resources: [
        {
          id: 'eneis-cold-breath',
          name: 'Souffle de froid',
          shortName: 'Souffle froid',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 5,
          maximumValue: 5,
          displayOrder: 10,
        },
        {
          id: 'eneis-metallic-breath',
          name: 'Souffle métallique',
          shortName: 'Souffle métallique',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 20,
        },
        {
          id: 'eneis-divine-sense',
          name: 'Sens divin',
          shortName: 'Sens divin',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 5,
          maximumValue: 5,
          displayOrder: 10,
        },
        {
          id: 'eneis-lightning-step',
          name: 'Déplacement éclair',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 5,
          maximumValue: 5,
          displayOrder: 52,
          hiddenFromTracker: true,

          unlockCondition: {
            progressionId: 'eneis-electric-charges',
            minimumValue: 50,
          },
        },
        {
          id: 'eneis-lay-on-hands',
          name: 'Imposition des mains',
          shortName: 'Imposition des mains',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 5,
          maximumValue: 65,
          displayOrder: 20,
        },
        {
          id: 'eneis-channel-divinity',
          name: 'Conduit divin',
          shortName: 'Conduit divin',
          category: 'class-feature',
          resetPeriod: 'short-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 30,
        },
        {
          id: 'eneis-detect-invisibility',
          name: 'Détection de l’invisibilité',
          shortName: 'Détection de l’invisibilité',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 50,
        },
        {
          id: 'eneis-spell-slot-1',
          name: 'Emplacements de sorts de niveau 1',
          shortName: 'Sorts niveau 1',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 0,
          maximumValue: 4,
          level: 1,
          displayOrder: 60,
        },
        {
          id: 'eneis-spell-slot-2',
          name: 'Emplacements de sorts de niveau 2',
          shortName: 'Sorts niveau 2',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 3,
          maximumValue: 3,
          level: 2,
          displayOrder: 70,
        },
        {
          id: 'eneis-spell-slot-3',
          name: 'Emplacements de sorts de niveau 3',
          shortName: 'Sorts niveau 3',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 0,
          maximumValue: 3,
          level: 3,
          displayOrder: 80,
        },
        {
          id: 'eneis-spell-slot-4',
          name: 'Emplacement de sort de niveau 4',
          shortName: 'Sort niveau 4',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          level: 4,
          displayOrder: 90,
        },
      ],
    },

    {
      id: 'character-riven',
      name: 'Riven',
      type: 'pc',

      className:
        'Roublard assassin 11 / Occultiste de l’Archi-guenaude 2',
      level: 13,

      hitPoints: {
        current: 53,
        maximum: 90,
        temporary: 0,
      },

      hitDice: [
        {
          id: 'riven-hit-dice',
          die: 'd8',
          current: 10,
          maximum: 13,
        },
      ],

      resources: [
        {
          id: 'riven-mind-assault',
          name: 'Assaut de l’esprit',
          shortName: 'Assaut de l’esprit',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 3,
          maximumValue: 3,
          displayOrder: 10,
        },
        {
          id: 'riven-detect-invisibility',
          name: 'Détection de l’invisibilité',
          shortName: 'Détection de l’invisibilité',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 20,
        },
        {
          id: 'riven-warlock-spell-slot-1',
          name: 'Emplacements d’occultiste de niveau 1',
          shortName: 'Sorts occultiste N1',
          category: 'spell-slot',
          resetPeriod: 'short-rest',
          currentValue: 2,
          maximumValue: 2,
          level: 1,
          displayOrder: 30,
        },
      ],
    },

    {
      id: 'character-jakharis',
      name: 'Jakharis',
      type: 'pc',

      className: 'Occultiste du Fiélon',
      level: 13,

      hitPoints: {
        current: 107,
        maximum: 107,
        temporary: 0,
      },

      hitDice: [
        {
          id: 'jakharis-hit-dice',
          die: 'd8',
          current: 13,
          maximum: 13,
        },
      ],

      resources: [
        {
          id: 'jakharis-dark-ones-luck',
          name: 'Chance du Ténébreux',
          shortName: 'Chance du Ténébreux',
          category: 'class-feature',
          resetPeriod: 'short-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 10,
        },
        {
          id: 'jakharis-fiendish-resilience',
          name: 'Résistance fiélonne',
          shortName: 'Résistance fiélonne',
          category: 'class-feature',
          resetPeriod: 'short-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 20,
          notes: '',
          notesEditable: true,
        },
        {
          id: 'jakharis-mystic-arcanum-6',
          name: 'Arcanum mystique de niveau 6',
          shortName: 'Arcanum mystique N6',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 30,
        },
        {
          id: 'jakharis-finger-of-death',
          name: 'Doigt de mort',
          shortName: 'Doigt de mort',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 40,
        },
        {
          id: 'jakharis-draconic-power',
          name: 'Souffle de feu / Peur du dragon',
          shortName: 'Pouvoir draconique',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 5,
          maximumValue: 5,
          displayOrder: 50,
        },
        {
          id: 'jakharis-chromatic-protection',
          name: 'Protection chromatique',
          shortName: 'Protection chromatique',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 60,
        },
        {
          id: 'jakharis-detect-invisibility',
          name: 'Détection de l’invisibilité',
          shortName: 'Détection de l’invisibilité',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 70,
        },
        {
          id: 'jakharis-warlock-spell-slot-5',
          name: 'Emplacements d’occultiste de niveau 5',
          shortName: 'Sorts occultiste N5',
          category: 'spell-slot',
          resetPeriod: 'short-rest',
          currentValue: 3,
          maximumValue: 3,
          level: 5,
          displayOrder: 80,
        },
      ],
    },

    {
      id: 'character-rohunar',
      name: 'Rohunar',
      type: 'pc',

      className: 'Rôdeuse chasseuse de monstres',
      level: 13,

      hitPoints: {
        current: 37,
        maximum: 124,
        temporary: 0,
      },

      hitDice: [
        {
          id: 'rohunar-hit-dice',
          die: 'd10',
          current: 10,
          maximum: 13,
        },
      ],

      progressions: [
        {
          id: 'rohunar-venlee-pact',
          name: 'Pacte de Venlee',
          currentValue: 7,
          minimumValue: 1,
          accentColor: '#5f8f65',

          states: [
            {
              label: 'Marques du dragon',
              minimumValue: 1,
              maximumValue: 9,
              iconUrl:
                '/assets/status/progressions/rohunar/01-griffes-dragon.png',
            },
            {
              label: 'Lierre rampant',
              minimumValue: 10,
              maximumValue: 17,
              iconUrl:
                '/assets/status/progressions/rohunar/02-lierre.png',
            },
            {
              label: 'Éveil électrique',
              minimumValue: 18,
              maximumValue: 24,
              iconUrl:
                '/assets/status/progressions/rohunar/03-etincelles.png',
            },
            {
              label: 'Tatouage foudroyant',
              minimumValue: 25,
              maximumValue: 29,
              iconUrl:
                '/assets/status/progressions/rohunar/04-foudre.png',
            },
            {
              label: 'Kobold maudite',
              minimumValue: 30,
              iconUrl:
                '/assets/status/progressions/rohunar/05-kobold-maudite.png',
            },
          ],
        },
      ],

      resources: [
        {
          id: 'rohunar-hunters-sense',
          name: 'Sens du chasseur',
          shortName: 'Sens du chasseur',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 2,
          maximumValue: 3,
          displayOrder: 10,
        },
        {
          id: 'rohunar-magic-users-nemesis',
          name: 'Némésis des adeptes de magie',
          shortName: 'Némésis magique',
          category: 'class-feature',
          resetPeriod: 'short-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 20,
        },
        {
          id: 'rohunar-entangle',
          name: 'Enchevêtrement',
          shortName: 'Enchevêtrement',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 5,
          maximumValue: 5,
          displayOrder: 30,

          unlockCondition: {
            progressionId: 'rohunar-venlee-pact',
            minimumValue: 10,
          },
        },
        {
          id: 'rohunar-detect-invisibility',
          name: 'Détection de l’invisibilité',
          shortName: 'Détection de l’invisibilité',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 40,
        },
        {
          id: 'rohunar-spell-slot-1',
          name: 'Emplacements de sorts de niveau 1',
          shortName: 'Sorts niveau 1',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 4,
          maximumValue: 4,
          level: 1,
          displayOrder: 50,
        },
        {
          id: 'rohunar-spell-slot-2',
          name: 'Emplacements de sorts de niveau 2',
          shortName: 'Sorts niveau 2',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 3,
          maximumValue: 3,
          level: 2,
          displayOrder: 60,
        },
        {
          id: 'rohunar-spell-slot-3',
          name: 'Emplacements de sorts de niveau 3',
          shortName: 'Sorts niveau 3',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 3,
          maximumValue: 3,
          level: 3,
          displayOrder: 70,
        },
        {
          id: 'rohunar-spell-slot-4',
          name: 'Emplacement de sort de niveau 4',
          shortName: 'Sort niveau 4',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          level: 4,
          displayOrder: 80,
        },
      ],
    },

    {
      id: 'character-azriel',
      name: 'Azriel',
      type: 'pc',

      className: 'Ensorceleur de l’Âme divine',
      level: 13,

      hitPoints: {
        current: 93,
        maximum: 93,
        temporary: 0,
      },

      hitDice: [
        {
          id: 'azriel-hit-dice',
          die: 'd6',
          current: 13,
          maximum: 13,
        },
      ],

      resources: [
        {
          id: 'azriel-sorcery-points',
          name: 'Points de sorcellerie',
          shortName: 'Points de sorcellerie',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 15,
          maximumValue: 15,
          displayOrder: 10,
        },
        {
          id: 'azriel-favored-by-the-gods',
          name: 'Favori des dieux',
          shortName: 'Favori des dieux',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 20,
        },
        {
          id: 'azriel-empowered-healing',
          name: 'Soins améliorés',
          shortName: 'Soins améliorés',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 30,
        },
        {
          id: 'azriel-healing-hands',
          name: 'Main guérisseuse',
          shortName: 'Main guérisseuse',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 40,
        },
        {
          id: 'azriel-celestial-revelation',
          name: 'Révélation céleste',
          shortName: 'Révélation céleste',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 50,
        },
        {
          id: 'azriel-spell-slot-1',
          name: 'Emplacements de sorts de niveau 1',
          shortName: 'Sorts niveau 1',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 4,
          maximumValue: 4,
          level: 1,
          displayOrder: 60,
        },
        {
          id: 'azriel-spell-slot-2',
          name: 'Emplacements de sorts de niveau 2',
          shortName: 'Sorts niveau 2',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 3,
          maximumValue: 3,
          level: 2,
          displayOrder: 70,
        },
        {
          id: 'azriel-spell-slot-3',
          name: 'Emplacements de sorts de niveau 3',
          shortName: 'Sorts niveau 3',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 3,
          maximumValue: 3,
          level: 3,
          displayOrder: 80,
        },
        {
          id: 'azriel-spell-slot-4',
          name: 'Emplacements de sorts de niveau 4',
          shortName: 'Sorts niveau 4',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 3,
          maximumValue: 3,
          level: 4,
          displayOrder: 90,
        },
        {
          id: 'azriel-spell-slot-5',
          name: 'Emplacements de sorts de niveau 5',
          shortName: 'Sorts niveau 5',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 2,
          maximumValue: 2,
          level: 5,
          displayOrder: 100,
        },
        {
          id: 'azriel-spell-slot-6',
          name: 'Emplacement de sort de niveau 6',
          shortName: 'Sort niveau 6',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          level: 6,
          displayOrder: 110,
        },
        {
          id: 'azriel-spell-slot-7',
          name: 'Emplacement de sort de niveau 7',
          shortName: 'Sort niveau 7',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          level: 7,
          displayOrder: 120,
        },
      ],
    },
    {
      id: 'character-anoukis',
      name: 'Anoukis',
      type: 'pc',

      className: 'Ensorceleur de la Magie sauvage',
      level: 13,

      hitPoints: {
        current: 93,
        maximum: 93,
        temporary: 0,
      },

      hitDice: [
        {
          id: 'anoukis-hit-dice',
          die: 'd6',
          current: 13,
          maximum: 13,
        },
      ],

      resources: [
        {
          id: 'anoukis-sorcery-points',
          name: 'Points de sorcellerie',
          shortName: 'Points de sorcellerie',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 15,
          maximumValue: 15,
          displayOrder: 10,
        },
        {
          id: 'anoukis-wave-walk',
          name: 'Marche sur l’onde',
          shortName: 'Marche sur l’onde',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 20,
        },
        {
          id: 'anoukis-create-destroy-water',
          name: 'Création ou destruction d’eau',
          shortName: 'Création ou destruction d’eau',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 30,
        },
        {
          id: 'anoukis-command',
          name: 'Injonction',
          shortName: 'Injonction',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 40,
        },
        {
          id: 'anoukis-misty-step',
          name: 'Foulée brumeuse',
          shortName: 'Foulée brumeuse',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 50,
        },
        {
          id: 'anoukis-spell-slot-1',
          name: 'Emplacements de sorts de niveau 1',
          shortName: 'Sorts niveau 1',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 4,
          maximumValue: 4,
          level: 1,
          displayOrder: 60,
        },
        {
          id: 'anoukis-spell-slot-2',
          name: 'Emplacements de sorts de niveau 2',
          shortName: 'Sorts niveau 2',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 3,
          maximumValue: 3,
          level: 2,
          displayOrder: 70,
        },
        {
          id: 'anoukis-spell-slot-3',
          name: 'Emplacements de sorts de niveau 3',
          shortName: 'Sorts niveau 3',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 3,
          maximumValue: 3,
          level: 3,
          displayOrder: 80,
        },
        {
          id: 'anoukis-spell-slot-4',
          name: 'Emplacements de sorts de niveau 4',
          shortName: 'Sorts niveau 4',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 3,
          maximumValue: 3,
          level: 4,
          displayOrder: 90,
        },
        {
          id: 'anoukis-spell-slot-5',
          name: 'Emplacements de sorts de niveau 5',
          shortName: 'Sorts niveau 5',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 2,
          maximumValue: 2,
          level: 5,
          displayOrder: 100,
        },
        {
          id: 'anoukis-spell-slot-6',
          name: 'Emplacement de sort de niveau 6',
          shortName: 'Sort niveau 6',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          level: 6,
          displayOrder: 110,
        },
        {
          id: 'anoukis-spell-slot-7',
          name: 'Emplacement de sort de niveau 7',
          shortName: 'Sort niveau 7',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          level: 7,
          displayOrder: 120,
        },
      ],
    },
  ],

  initialLiveState: {
    sessionId: 'session-vecna',
    campaignId: 'campaign-vecna-template',

    fogEnabled: false,
    displayMode: 'normal',
    status: 'draft',

    day: 1,
    dayPeriod: 'Nuit',

    weather: {
      id: 'cloudy',
      label: 'Couvert',
      imageUrl:
        '/assets/weather/cloudy.png',
      alt: 'Ciel couvert de nuages sombres',
    },

    moon: {
      id: 'full-moon',
      label: 'Pleine lune',
      imageUrl:
        '/assets/moons/full-moon.png',
      alt: 'Pleine lune',
    },

    location: {
      name: 'Plan matériel',
      subtitle:
        'Une présence observe dans l’ombre',
    },

    displayedMedia: undefined,
  },
};

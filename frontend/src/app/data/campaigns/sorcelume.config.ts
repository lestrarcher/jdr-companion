import { CampaignConfig } from '@core/models/campaign.model';
import {
  STRAHD_MOON_PHASES,
  STRAHD_WEATHER_STATES,
} from '@data/campaigns/strahd.config';

export const SORCELUME_CAMPAIGN: CampaignConfig = {
  id: 'campaign-sorcelume-template',
  name: 'Les Terres de la Sorcelume',
  worldName: 'Sorcelume',

  moonPhases: STRAHD_MOON_PHASES,
  weatherStates: STRAHD_WEATHER_STATES,

  theme: {
    backgroundImage:
      '/assets/backgrounds/background-sorcelume.png',

    colors: {
      primary: '#d9e9ff',
      accent: '#8dcfc8',
      text: '#f8f5ff',
    },

    effects: {
      fog: false,
    },
  },

  players: [
    {
      id: 'player-sylveon',
      displayName: 'Micka',
      characterId: 'character-sylveon',
      accessToken: 'demo-sylveon',
    },
  ],

  characters: [
    {
      id: 'character-sylveon',
      name: 'Sylveon',
      type: 'pc',
      className: 'Magicien Chantelame',
      level: 1,

      hitPoints: {
        current: 8,
        maximum: 8,
        temporary: 0,
      },

      hitDice: [
        {
          id: 'sylveon-hit-dice',
          die: 'd6',
          current: 1,
          maximum: 1,
        },
      ],

      resources: [
        {
          id: 'sylveon-arcane-recovery',
          name: 'Restauration arcanique',
          shortName: 'Restauration arcanique',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 1,
          displayOrder: 10,
        },
        {
          id: 'sylveon-fey-step',
          name: 'Foulée féerique',
          shortName: 'Foulée féerique',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 1,
          maximumValue: 2,
          displayOrder: 20,
        },
        {
          id: 'sylveon-bladesong',
          name: 'Chantelame',
          shortName: 'Chantelame',
          category: 'class-feature',
          resetPeriod: 'long-rest',
          currentValue: 0,
          maximumValue: 2,
          displayOrder: 30,
        },
        {
          id: 'sylveon-spell-slot-1',
          name: 'Emplacements de sorts de niveau 1',
          shortName: 'Sorts niveau 1',
          category: 'spell-slot',
          resetPeriod: 'long-rest',
          currentValue: 3,
          maximumValue: 3,
          level: 1,
          displayOrder: 40,
        },
      ],
    },
  ],

  initialLiveState: {
    sessionId: 'session-sorcelume',
    campaignId: 'campaign-sorcelume-template',

    fogEnabled: false,
    displayMode: 'normal',
    status: 'draft',

    day: 1,
    dayPeriod: 'Crépuscule',

    weather: {
      id: 'cloudy',
      label: 'Couvert',
      imageUrl: '/assets/weather/cloudy.png',
      alt: 'Ciel couvert',
    },

    moon: {
      id: 'full-moon',
      label: 'Pleine lune',
      imageUrl: '/assets/moons/full-moon.png',
      alt: 'Pleine lune',
    },

    location: {
      name: 'Sorcelume',
      subtitle: 'À la lisière des royaumes féeriques',
    },

    displayedMedia: undefined,
  },
};

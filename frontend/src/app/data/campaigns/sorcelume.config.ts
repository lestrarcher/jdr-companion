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

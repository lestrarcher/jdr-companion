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

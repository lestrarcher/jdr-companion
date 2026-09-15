import { CampaignConfig } from '@core/models/campaign.model';

export const STRAHD_CAMPAIGN: CampaignConfig = {
  id: 'campaign-strahd-01',
  name: 'La Malédiction de Strahd',
  worldName: 'Barovie',

  theme: {
    backgroundImage: '/assets/backgrounds/background-strahd.png',

    colors: {
      primary: '#ddd5c3',
      accent: '#c7a55b',
      text: '#f4efe5',
    },

    effects: {
      fog: true,
    },
  },

  initialLiveState: {
    sessionId: 'session-samedi',
    campaignId: 'campaign-strahd-01',
    fogEnabled: true,
    displayMode: 'normal',
    status: 'draft',

    day: 11,
    dayPeriod: 'Nuit',

    weather: {
      id: 'fog',
      label: 'Brouillard',
      imageUrl: '/assets/weather/fog.png',
      alt: 'Épaisses nappes de brouillard',
    },

    moon: {
      id: 'waxing-crescent',
      label: 'Premier croissant',
      imageUrl: '/assets/moons/waxing-crescent.png',
      alt: 'Premier croissant de lune',
    },

    location: {
      name: 'Argynvostholt',
      subtitle: 'Lisière de la forêt',
    },

    displayedMedia: {
      id: 'strahd-cover',
      source: '/assets/display/curse-of-strahd.png',
      alt: 'Strahd von Zarovich devant le château Ravenloft',
      title: 'La Malédiction de Strahd',
      subtitle: 'Bienvenue en Barovie',
      fit: 'contain',
    },
  },
};

import { CampaignConfig } from '@core/models/campaign.model';
import {
  MoonState,
  WeatherState,
} from '@core/models/live-session-state.model';

export const STRAHD_WEATHER_STATES: WeatherState[] = [
  {
    id: 'cloudy',
    label: 'Couvert',
    imageUrl: '/assets/weather/cloudy.png',
    alt: 'Ciel couvert de nuages sombres',
  },
  {
    id: 'rain',
    label: 'Pluie',
    imageUrl: '/assets/weather/rain.png',
    alt: 'Nuages sombres et pluie',
  },
  {
    id: 'storm',
    label: 'Orage',
    imageUrl: '/assets/weather/storm.png',
    alt: 'Orage accompagné de pluie et d’éclairs',
  },
  {
    id: 'sunny',
    label: 'Soleil',
    imageUrl: '/assets/weather/sunny.png',
    alt: 'Soleil apparaissant derrière les nuages',
  },
  {
    id: 'fog',
    label: 'Brouillard',
    imageUrl: '/assets/weather/fog.png',
    alt: 'Épaisses nappes de brouillard',
  },
  {
    id: 'snow',
    label: 'Neige',
    imageUrl: '/assets/weather/snow.png',
    alt: 'Chute de neige calme',
  },
  {
    id: 'blizzard',
    label: 'Blizzard',
    imageUrl: '/assets/weather/blizzard.png',
    alt: 'Violente tempête de neige',
  },
  {
    id: 'wind',
    label: 'Vent fort',
    imageUrl: '/assets/weather/wind.png',
    alt: 'Fortes rafales de vent',
  },
];

export const STRAHD_MOON_PHASES: MoonState[] = [
  {
    id: 'new-moon',
    label: 'Nouvelle lune',
    imageUrl: '/assets/moons/new-moon.png',
    alt: 'Nouvelle lune',
  },
  {
    id: 'waxing-crescent',
    label: 'Premier croissant',
    imageUrl: '/assets/moons/waxing-crescent.png',
    alt: 'Premier croissant de lune',
  },
  {
    id: 'first-quarter',
    label: 'Premier quartier',
    imageUrl: '/assets/moons/first-quarter.png',
    alt: 'Premier quartier de lune',
  },
  {
    id: 'waxing-gibbous',
    label: 'Lune gibbeuse croissante',
    imageUrl: '/assets/moons/waxing-gibbous.png',
    alt: 'Lune gibbeuse croissante',
  },
  {
    id: 'full-moon',
    label: 'Pleine lune',
    imageUrl: '/assets/moons/full-moon.png',
    alt: 'Pleine lune',
  },
  {
    id: 'waning-gibbous',
    label: 'Lune gibbeuse décroissante',
    imageUrl: '/assets/moons/waning-gibbous.png',
    alt: 'Lune gibbeuse décroissante',
  },
  {
    id: 'last-quarter',
    label: 'Dernier quartier',
    imageUrl: '/assets/moons/last-quarter.png',
    alt: 'Dernier quartier de lune',
  },
  {
    id: 'waning-crescent',
    label: 'Dernier croissant',
    imageUrl: '/assets/moons/waning-crescent.png',
    alt: 'Dernier croissant de lune',
  },
];

export const STRAHD_CAMPAIGN: CampaignConfig = {
  id: 'campaign-strahd-01',
  name: 'La Malédiction de Strahd',
  worldName: 'Barovie',
  moonPhases: STRAHD_MOON_PHASES,
  weatherStates: STRAHD_WEATHER_STATES,

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
  media: [
    {
      id: 'strahd-cover',
      label: 'Illustration principale',
      source: '/assets/display/curse-of-strahd.png',
      alt: 'Strahd von Zarovich devant le château Ravenloft',
      title: 'La Malédiction de Strahd',
      subtitle: 'Bienvenue en Barovie',
      fit: 'contain',
    },
    {
      id: 'argynvostholt',
      label: 'Argynvostholt',
      source: '/assets/display/argynvostholt.gif',
      alt: 'Le manoir en ruine d’Argynvostholt',
      title: 'Argynvostholt',
      subtitle: 'Le mausolée de l’Ordre du Dragon d’Argent',
      fit: 'cover',
    },
    {
      id: 'ravenloft',
      label: 'Ravenloft',
      source: '/assets/display/ravenloft.gif',
      alt: 'Le château de Ravenloft',
      title: 'Ravenloft',
      subtitle: 'Le mausolée de l’Ordre du Dragon d’Argent',
      fit: 'cover',
    },
    {
      id: 'ezmerelda',
      label: 'Ezmerelda',
      source: '/assets/display/ezmeralda.webp',
      alt: 'Ezmerelda d’Avenir',
      title: 'Ezmerelda d’Avenir',
      subtitle: 'Chasseuse de monstres',
      fit: 'contain',
    },
    {
      id: 'arrigal',
      label: 'Arrigal et ses Vistanis',
      source: '/assets/display/arrigal-wolves.png',
      alt: 'Ezmerelda d’Avenir',
      title: 'Ezmerelda d’Avenir',
      subtitle: 'Chasseuse de monstres',
      fit: 'contain',
    },
    {
      id: 'blue-water-inn',
      label: `La Taverne de l'Eau Bleue`,
      source: '/assets/display/blue-water-inn.gif',
      alt: '',
      title: '',
      subtitle: '',
      fit: 'contain',
    },
    {
      id: 'dawnalee-space',
      label: `Introspection d'Hailee`,
      source: '/assets/display/dawnalee-space.png',
      alt: '',
      title: '',
      subtitle: '',
      fit: 'contain',
    },
    {
      id: 'luna-river-crossroads',
      label: `Le Croisement de la Luna`,
      source: '/assets/display/luna-river-crossroads.gif',
      alt: '',
      title: '',
      subtitle: '',
      fit: 'contain',
    },
    {
      id: 'svalich',
      label: `Le Bois de Svalich`,
      source: '/assets/display/svalich.webp',
      alt: '',
      title: '',
      subtitle: '',
      fit: 'contain',
    },
    {
      id: 'tower',
      label: `La tour`,
      source: '/assets/display/tower.gif',
      alt: '',
      title: '',
      subtitle: '',
      fit: 'contain',
    },
    {
      id: 'vallaki-cultists',
      label: `Les rues sinistres de Vallaki`,
      source: '/assets/display/vallaki-cultists.png',
      alt: '',
      title: '',
      subtitle: '',
      fit: 'contain',
    },
    {
      id: 'vallaki-night',
      label: `Arrivée à Vallaki`,
      source: '/assets/display/vallaki-night.gif',
      alt: '',
      title: '',
      subtitle: '',
      fit: 'contain',
    },
  ],

  initialLiveState: {
    sessionId: 'session-samedi',
    campaignId: 'campaign-strahd-01',
    fogEnabled: true,
    displayMode: 'normal',

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

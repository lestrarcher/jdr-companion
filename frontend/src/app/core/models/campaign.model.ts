import {
  DisplayedMediaState,
  LiveSessionState,
  MoonState,
  WeatherState,
} from './live-session-state.model';

export interface CampaignColors {
  primary: string;
  accent: string;
  text: string;
}

export interface CampaignEffects {
  fog: boolean;
}

export interface CampaignTheme {
  backgroundImage: string;
  colors: CampaignColors;
  effects: CampaignEffects;
}

export interface CampaignConfig {
  id: string;
  name: string;
  worldName: string;
  theme: CampaignTheme;

  moonPhases: MoonState[];
  weatherStates: WeatherState[];

  initialLiveState: LiveSessionState;
}

import {
  DisplayedMediaState,
  LiveSessionState,
  MoonState,
  WeatherState,
} from './live-session-state.model';
import { CampaignPlayer } from './campaign-player.model';
import { Character } from './character.model';

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

export interface CampaignMedia extends DisplayedMediaState {
  label: string;
  thumbnail?: string;
}

export interface CampaignConfig {
  id: string;
  name: string;
  worldName: string;
  theme: CampaignTheme;

  players: CampaignPlayer[];
  characters: Character[];

  moonPhases: MoonState[];
  weatherStates: WeatherState[];
  media: CampaignMedia[];

  initialLiveState: LiveSessionState;
}

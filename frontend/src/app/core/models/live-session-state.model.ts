import type { GameSessionStatus } from './game-session.model';

export interface WeatherState {
  id: string;
  label: string;
  imageUrl: string;
  alt: string;
}

export interface MoonState {
  id: string;
  label: string;
  imageUrl: string;
  alt: string;
}

export interface LocationState {
  name: string;
  subtitle?: string;
}

export interface DisplayedMediaState {
  id: string;
  source: string;
  alt: string;
  title?: string;
  subtitle?: string;
  fit?: 'contain' | 'cover';
}

export type DisplayMode =
  | 'normal'
  | 'cinematic';

export type FigurePanelMode =
  | 'party'
  | 'important-npcs'
  | 'memorial'
  | 'hidden';

export interface LiveSessionState {
  sessionId: string;
  campaignId: string;
  status: GameSessionStatus;

  fogEnabled: boolean;
  displayMode: DisplayMode;

  /*
   * Facultatif pour rester compatible avec les
   * configurations et états enregistrés avant
   * l’ajout du panneau des figures.
   */
  figurePanelMode?: FigurePanelMode;

  day?: number;
  dayPeriod?: string;
  weather?: WeatherState;
  moon?: MoonState;
  location?: LocationState;
  displayedMedia?: DisplayedMediaState;
}

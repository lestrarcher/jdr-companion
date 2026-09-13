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

export type InitiativeStatus =
| 'requested'
| 'active';

export interface InitiativeParticipant {
  id: string;
  name: string;
  initiative: number;
  characterId?: number;
  mediaId?: number;
  imageUrl?: string;
  bloodied: boolean;
}

export interface InitiativeState {
  status: InitiativeStatus;
  requestedAt: number;
  round: number;
  currentIndex: number;

  draftParticipants: InitiativeDraftParticipant[];

  participants: InitiativeParticipant[];
}

export interface InitiativeDraftParticipant {
  id: string;
  name: string;
  initiative: number | null;
  characterId?: number;
  currentHitPoints?: number;
  maximumHitPoints?: number | null;
  imageUrl?: string;
  bloodied: boolean;
}

export interface LiveSessionState {
  sessionId: string;
  campaignId: string;
  status: GameSessionStatus;

  fogEnabled: boolean;
  displayMode: DisplayMode;

  figurePanelMode?: FigurePanelMode;

  day?: number;
  dayPeriod?: string;

  weather?: WeatherState;
  showWeather?: boolean;

  moon?: MoonState;
  showMoonPhase?: boolean;

  location?: LocationState;
  displayedMedia?: DisplayedMediaState;
  initiative?: InitiativeState;
}

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
  initiative?: InitiativeState;
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

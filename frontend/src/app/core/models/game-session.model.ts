import { LiveSessionState } from './live-session-state.model';

export type GameSessionStatus = 'draft' | 'live' | 'closed';

export interface GameSession {
  id: string;
  campaignId: string;
  name: string;
  status: GameSessionStatus;
  scheduledAt?: string;
  liveState: LiveSessionState;
}

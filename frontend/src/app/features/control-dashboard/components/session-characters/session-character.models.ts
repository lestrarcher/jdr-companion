import { CharacterApiResponse } from '@core/services/character-api.service';
import { CharacterSessionStateApiResponse } from '@core/services/character-session-state-api.service';

export interface SessionCharacterView {
  character: CharacterApiResponse;
  sessionState: CharacterSessionStateApiResponse | null;
  participating: boolean;
}

export interface MaximumHitPointAdjustment {
  character: SessionCharacterView;
  amount: number;
  direction: -1 | 1;
}

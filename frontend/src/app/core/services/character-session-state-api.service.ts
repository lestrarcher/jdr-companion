import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable, map } from 'rxjs';

import {
  CharacterSessionStatePayload,
} from '@core/mappers/character-api.mapper';

import {
  CharacterProfile,
  LevelUpOptions,
  LevelUpPayload,
  LevelUpResponse,
} from '@core/services/character-api.service';

import {
  CampaignConfigurationKey,
} from '@core/services/campaign-api.service';

export interface CharacterActionSummary {
  slug: string;
  name: string;
  description: string | null;
  handlerType: string;
  requiresPreparation: boolean;
  budget?: number | null;
}

export interface CharacterActionsState {
  prepared: string[];
  preparationPending: boolean;
}

export interface CharacterActiveEffectSummary {
  id: number;
  type: string;
  amount: number;
  sourceCharacter: {
    id: number;
    name: string;
  } | null;
}

export interface CharacterActionTarget {
  id: number;
  name: string;
}

interface CharacterActionTargetsResponse {
  targets: CharacterActionTarget[];
}

export interface CharacterSessionStateApiResponse {
  id: number;
  revision: number;

  campaign: {
    id: number;
    configurationKey: CampaignConfigurationKey;
  };

  session: {
    id: number;
    name: string;
    status: 'draft' | 'live' | 'closed';
  };

  activeEffects: CharacterActiveEffectSummary[];

  character: CharacterProfile;
  participating: boolean;
  levelUpAllowed: boolean;
  state: CharacterSessionStatePayload;
  accessToken?: string;
  updatedAt: string;
}

interface CharacterSessionStateListResponse {
  states: CharacterSessionStateApiResponse[];
}

@Injectable({
  providedIn: 'root',
})
export class CharacterSessionStateApiService {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = '/api';

  list(sessionId: number): Observable<CharacterSessionStateApiResponse[]> {
    return this.http
      .get<CharacterSessionStateListResponse>(
        `${this.apiUrl}/sessions/${sessionId}/characters`,
      )
      .pipe(map(response => response.states));
  }

  create(
    sessionId: number,
    characterId: number,
  ): Observable<CharacterSessionStateApiResponse> {
    return this.http.post<CharacterSessionStateApiResponse>(
      `${this.apiUrl}/sessions/${sessionId}/characters/${characterId}`,
      {},
    );
  }

  remove(
    sessionId: number,
    characterId: number,
  ): Observable<CharacterSessionStateApiResponse> {
    return this.http.delete<CharacterSessionStateApiResponse>(
      `${this.apiUrl}/sessions/${sessionId}/characters/${characterId}`,
    );
  }

  getByAccessToken(
    accessToken: string,
  ): Observable<CharacterSessionStateApiResponse> {
    return this.http.get<CharacterSessionStateApiResponse>(
      `${this.apiUrl}/public/characters/${accessToken}`,
    );
  }

  getLevelUpOptions(
    accessToken: string,
  ): Observable<LevelUpOptions> {
    return this.http.get<LevelUpOptions>(
      `${this.apiUrl}/public/characters/${accessToken}/level-up/options`,
    );
  }

  levelUp(
    accessToken: string,
    payload: LevelUpPayload,
  ): Observable<LevelUpResponse> {
    return this.http.post<LevelUpResponse>(
      `${this.apiUrl}/public/characters/${accessToken}/level-up`,
      payload,
    );
  }

  updateByAccessToken(
    accessToken: string,
    state: CharacterSessionStatePayload,
    revision: number,
  ): Observable<CharacterSessionStateApiResponse> {
    return this.http.patch<CharacterSessionStateApiResponse>(
      `${this.apiUrl}/public/characters/${accessToken}`,
      { state, revision },
    );
  }

  setLevelUpPermission(sessionId: number, characterId: number, allowed: boolean): Observable<CharacterSessionStateApiResponse>
  {
    return this.http.patch<CharacterSessionStateApiResponse>(`${this.apiUrl}/sessions/${sessionId}/characters/${characterId}/level-up-permission`, { allowed });
  }

  adjustMaximumHitPoints(sessionId: number, characterId: number, delta: number ): Observable<CharacterSessionStateApiResponse>
  {
    return this.http.post<CharacterSessionStateApiResponse>(`${this.apiUrl}/sessions/${sessionId}/characters/${characterId}/hit-points/maximum-adjustment`, { delta });
  }

  updatePreparedActions(accessToken: string, prepared: string[], revision: number): Observable<CharacterSessionStateApiResponse>
  {
    return this.http.patch<CharacterSessionStateApiResponse>(`${this.apiUrl}/public/characters/${accessToken}/actions/prepared`, { prepared, revision });
  }

  getActionTargets(accessToken: string): Observable<CharacterActionTarget[]>
  {
    return this.http.get<CharacterActionTargetsResponse>(`${this.apiUrl}/public/characters/${accessToken}/action-targets`)
      .pipe(map(response => response.targets));
  }

  useAid( accessToken: string, spellSlotLevel: number, targetIds: number[], revision: number): Observable<CharacterSessionStateApiResponse>
  {
    return this.http.post<CharacterSessionStateApiResponse>(`${this.apiUrl}/public/characters/${accessToken}/actions/aid`, { spellSlotLevel, targetIds, revision });
  }

  useHeroesFeast(accessToken: string, hitPointBonus: number, targetIds: number[], revision: number): Observable<CharacterSessionStateApiResponse>
  {
    return this.http.post<CharacterSessionStateApiResponse>(`${this.apiUrl}/public/characters/${accessToken}/actions/heroes-feast`, { hitPointBonus, targetIds, revision });
  }

  createFlexibleCastingSlot(accessToken: string, level: number, revision: number): Observable<CharacterSessionStateApiResponse> {
    return this.http.post<CharacterSessionStateApiResponse>(`${this.apiUrl}/public/characters/${accessToken}/actions/flexible-casting/create-spell-slot`, { level, revision });
  }

  convertFlexibleCastingSlot(accessToken: string, level: number, revision: number): Observable<CharacterSessionStateApiResponse> {
    return this.http.post<CharacterSessionStateApiResponse>(`${this.apiUrl}/public/characters/${accessToken}/actions/flexible-casting/convert-spell-slot`, { level, revision });
  }

  recoverArcaneSlots(accessToken: string, slots: Record<number, number>, revision: number): Observable<CharacterSessionStateApiResponse> {
    return this.http.post<CharacterSessionStateApiResponse>(`${this.apiUrl}/public/characters/${accessToken}/actions/arcane-recovery`, { slots, revision });
  }

  endActiveEffect(sessionId: number, characterId: number, effectId: number): Observable<CharacterSessionStateApiResponse>
  {
    return this.http.delete<CharacterSessionStateApiResponse>(`${this.apiUrl}/sessions/${sessionId}/characters/${characterId}/active-effects/${effectId}`);
  }
}

import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';

import { CharacterSessionStatePayload } from '@core/mappers/character-api.mapper';

export interface CharacterSessionStateApiResponse {
  id: number;

  session: {
    id: number;
    name: string;
    status: 'draft' | 'live' | 'closed';
  };

  character: {
    id: number;
    slug: string;
    name: string;
    playerName: string | null;
    type: 'player' | 'npc';
    definition: Record<string, unknown>;
  };

  state: CharacterSessionStatePayload;
  accessToken?: string;
  updatedAt: string;
}

@Injectable({
  providedIn: 'root',
})
export class CharacterSessionStateApiService {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = '/api';

  create(
    sessionId: number,
    characterId: number,
    state: CharacterSessionStatePayload,
  ): Observable<CharacterSessionStateApiResponse> {
    return this.http.post<CharacterSessionStateApiResponse>(
      `${this.apiUrl}/sessions/${sessionId}/characters/${characterId}`,
      { state },
    );
  }

  getByAccessToken(
    accessToken: string,
  ): Observable<CharacterSessionStateApiResponse> {
    return this.http.get<CharacterSessionStateApiResponse>(
      `${this.apiUrl}/public/characters/${accessToken}`,
    );
  }

  updateByAccessToken(
    accessToken: string,
    state: CharacterSessionStatePayload,
  ): Observable<CharacterSessionStateApiResponse> {
    return this.http.patch<CharacterSessionStateApiResponse>(
      `${this.apiUrl}/public/characters/${accessToken}`,
      { state },
    );
  }
}

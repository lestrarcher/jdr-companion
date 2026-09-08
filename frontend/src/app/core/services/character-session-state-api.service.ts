import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable, map } from 'rxjs';

import {
  CharacterSessionStatePayload,
} from '@core/mappers/character-api.mapper';

import {
  CharacterProfile,
} from '@core/services/character-api.service';

import {
  CampaignConfigurationKey,
} from '@core/services/campaign-api.service';

export interface CharacterSessionStateApiResponse {
  id: number;

  campaign: {
    id: number;
    configurationKey: CampaignConfigurationKey;
  };

  session: {
    id: number;
    name: string;
    status: 'draft' | 'live' | 'closed';
  };

  character: CharacterProfile;
  participating: boolean;
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

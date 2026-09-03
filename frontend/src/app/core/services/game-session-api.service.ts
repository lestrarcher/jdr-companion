import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

export type GameSessionStatus =
  | 'draft'
  | 'live'
  | 'closed';

export interface GameSessionApiResponse {
  id: number;
  campaignId: number;
  slug: string;
  name: string;
  status: GameSessionStatus;
  displayState: Record<string, unknown>;
  displayAccessToken: string;
  createdAt: string;
  updatedAt: string;
}

export interface CreateGameSessionPayload {
  slug: string;
  name: string;
}

interface GameSessionResponse {
  session: GameSessionApiResponse;
}

interface GameSessionListResponse {
  sessions: GameSessionApiResponse[];
}

@Injectable({
  providedIn: 'root',
})
export class GameSessionApiService {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = '/api';

  list(
    campaignId: number,
  ): Observable<GameSessionApiResponse[]> {
    return this.http
      .get<GameSessionListResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/sessions`,
      )
      .pipe(
        map((response) => response.sessions),
      );
  }

  get(
    sessionId: number,
  ): Observable<GameSessionApiResponse> {
    return this.http
      .get<GameSessionResponse>(
        `${this.apiUrl}/sessions/${sessionId}`,
      )
      .pipe(
        map((response) => response.session),
      );
  }

  create(
    campaignId: number,
    payload: CreateGameSessionPayload,
  ): Observable<GameSessionApiResponse> {
    return this.http
      .post<GameSessionResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/sessions`,
        payload,
      )
      .pipe(
        map((response) => response.session),
      );
  }

  updateStatus(
    sessionId: number,
    status: GameSessionStatus,
  ): Observable<GameSessionApiResponse> {
    return this.http
      .patch<GameSessionResponse>(
        `${this.apiUrl}/sessions/${sessionId}`,
        { status },
      )
      .pipe(
        map((response) => response.session),
      );
  }
}

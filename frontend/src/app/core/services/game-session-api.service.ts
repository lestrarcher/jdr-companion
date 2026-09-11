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
  preparationNotes: string | null;
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

export type GameSessionDisplayResponse = Pick<GameSessionApiResponse,
  'id' | 'campaignId' | 'status' | 'displayState' | 'updatedAt'>;

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

  getDisplay(sessionId: number): Observable<GameSessionDisplayResponse> {
    return this.http.get<{ session: GameSessionDisplayResponse }>(
      `${this.apiUrl}/sessions/${sessionId}/display`,
    ).pipe(map(response => response.session));
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

  updatePreparation(sessionId: number, preparationNotes: string | null): Observable<GameSessionApiResponse> {
    return this.http.patch<GameSessionResponse>(
      `${this.apiUrl}/sessions/${sessionId}`,
      { preparationNotes },
    ).pipe(map(response => response.session));
  }
}

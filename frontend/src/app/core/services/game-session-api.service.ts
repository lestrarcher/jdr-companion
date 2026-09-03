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

interface GameSessionResponse {
  session: GameSessionApiResponse;
}

@Injectable({
  providedIn: 'root',
})
export class GameSessionApiService {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = '/api';

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

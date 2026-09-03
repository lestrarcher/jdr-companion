import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { RestType } from '@core/models/rest-request.model';

export type RestRequestStatus =
  | 'pending'
  | 'approved'
  | 'rejected';

export interface RestRequestApiResponse {
  id: number;
  type: RestType;
  status: RestRequestStatus;
  requestedAt: string;
  resolvedAt: string | null;

  character: {
    id: number;
    slug: string;
    name: string;
    playerName: string | null;
  };
}

interface SingleRestRequestResponse {
  restRequest: RestRequestApiResponse | null;
}

interface RestRequestListResponse {
  restRequests: RestRequestApiResponse[];
}

@Injectable({
  providedIn: 'root',
})
export class RestRequestApiService {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = '/api';

  create(
    accessToken: string,
    type: RestType,
  ): Observable<RestRequestApiResponse> {
    return this.http
      .post<SingleRestRequestResponse>(
        `${this.apiUrl}/public/characters/${accessToken}/rest-requests`,
        { type },
      )
      .pipe(
        map((response) => {
          if (!response.restRequest) {
            throw new Error(
              'La demande de repos créée est introuvable.',
            );
          }

          return response.restRequest;
        }),
      );
  }

  getLatest(
    accessToken: string,
  ): Observable<RestRequestApiResponse | null> {
    return this.http
      .get<SingleRestRequestResponse>(
        `${this.apiUrl}/public/characters/${accessToken}/rest-requests/latest`,
      )
      .pipe(
        map((response) => response.restRequest),
      );
  }

  listPending(
    sessionId: number,
  ): Observable<RestRequestApiResponse[]> {
    return this.http
      .get<RestRequestListResponse>(
        `${this.apiUrl}/sessions/${sessionId}/rest-requests`,
      )
      .pipe(
        map((response) => response.restRequests),
      );
  }

  resolve(
    requestId: number,
    status: 'approved' | 'rejected',
  ): Observable<RestRequestApiResponse> {
    return this.http
      .patch<SingleRestRequestResponse>(
        `${this.apiUrl}/rest-requests/${requestId}`,
        { status },
      )
      .pipe(
        map((response) => {
          if (!response.restRequest) {
            throw new Error(
              'La demande de repos traitée est introuvable.',
            );
          }

          return response.restRequest;
        }),
      );
  }
}

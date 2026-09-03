import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

export interface CampaignApiResponse {
  id: number;
  slug: string;
  name: string;
}

export interface CreateCampaignPayload {
  slug: string;
  name: string;
}

interface CampaignListResponse {
  campaigns: CampaignApiResponse[];
}

interface SingleCampaignResponse {
  campaign: CampaignApiResponse;
}

@Injectable({
  providedIn: 'root',
})
export class CampaignApiService {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = '/api';

  list(): Observable<CampaignApiResponse[]> {
    return this.http
      .get<CampaignListResponse>(
        `${this.apiUrl}/campaigns`,
      )
      .pipe(
        map((response) => response.campaigns),
      );
  }

  create(
    payload: CreateCampaignPayload,
  ): Observable<CampaignApiResponse> {
    return this.http
      .post<SingleCampaignResponse>(
        `${this.apiUrl}/campaigns`,
        payload,
      )
      .pipe(
        map((response) => response.campaign),
      );
  }
}

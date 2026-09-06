import {
  Injectable,
  inject,
} from '@angular/core';
import { HttpClient } from '@angular/common/http';
import {
  Observable,
  map,
} from 'rxjs';

export type TipCategory =
  | 'rule'
  | 'advice'
  | 'lore';

export type TipStatus =
  | 'visible'
  | 'hidden';

export interface CampaignTip {
  id: number;
  campaignId: number;
  label: string;
  text: string;
  category: TipCategory;
  status: TipStatus;
  displayOrder: number;
  createdAt: string;
  updatedAt: string | null;
}

export interface CreateTipPayload {
  label: string;
  text: string;
  category: TipCategory;
}

export interface UpdateTipPayload {
  label?: string;
  text?: string;
  category?: TipCategory;
  status?: TipStatus;
  displayOrder?: number;
}

interface TipListResponse {
  tips: CampaignTip[];
}

interface TipResponse {
  tip: CampaignTip;
}

@Injectable({
  providedIn: 'root',
})
export class TipApiService {
  private readonly http =
    inject(HttpClient);

  private readonly apiUrl = '/api';

  list(
    campaignId: number,
  ): Observable<CampaignTip[]> {
    return this.http
      .get<TipListResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/tips`,
      )
      .pipe(
        map(
          (response) =>
            response.tips,
        ),
      );
  }

  create(
    campaignId: number,
    payload: CreateTipPayload,
  ): Observable<CampaignTip> {
    return this.http
      .post<TipResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/tips`,
        payload,
      )
      .pipe(
        map(
          (response) =>
            response.tip,
        ),
      );
  }

  update(
    tipId: number,
    payload: UpdateTipPayload,
  ): Observable<CampaignTip> {
    return this.http
      .patch<TipResponse>(
        `${this.apiUrl}/tips/${tipId}`,
        payload,
      )
      .pipe(
        map(
          (response) =>
            response.tip,
        ),
      );
  }
}

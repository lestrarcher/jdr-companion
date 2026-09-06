import {
  Injectable,
  inject,
} from '@angular/core';
import { HttpClient } from '@angular/common/http';
import {
  Observable,
  map,
} from 'rxjs';

export type FigureCharacterType =
  | 'pc'
  | 'npc';

export type FigureEncounterStatus =
  | 'unmet'
  | 'met';

export type FigureLifeStatus =
  | 'alive'
  | 'dead';

export type FigurePublicationStatus =
  | 'visible'
  | 'hidden';

export interface CampaignFigurePortrait {
  id: number;
  title: string | null;
  originalName: string;
  url: string;
}

export interface CampaignFigure {
  id: number;
  campaignId: number;
  name: string;
  characterType: FigureCharacterType;
  encounterStatus: FigureEncounterStatus;
  lifeStatus: FigureLifeStatus;
  description: string;
  deathLabel: string | null;
  publicationStatus:
    FigurePublicationStatus;
  displayOrder: number;
  portrait: CampaignFigurePortrait | null;
  createdAt: string;
  updatedAt: string | null;
}

export interface CreateCampaignFigurePayload {
  name: string;
  characterType: FigureCharacterType;
  description: string;
  deathLabel?: string | null;
  portraitId?: number | null;
  encounterStatus?: FigureEncounterStatus;
  lifeStatus?: FigureLifeStatus;
}

export interface UpdateCampaignFigurePayload {
  name?: string;
  characterType?: FigureCharacterType;
  encounterStatus?: FigureEncounterStatus;
  lifeStatus?: FigureLifeStatus;
  description?: string;
  deathLabel?: string | null;
  portraitId?: number | null;
  publicationStatus?:
    FigurePublicationStatus;
  displayOrder?: number;
}

interface FigureListResponse {
  figures: CampaignFigure[];
}

interface FigureResponse {
  figure: CampaignFigure;
}

@Injectable({
  providedIn: 'root',
})
export class CampaignFigureApiService {
  private readonly http =
    inject(HttpClient);

  private readonly apiUrl = '/api';

  list(
    campaignId: number,
  ): Observable<CampaignFigure[]> {
    return this.http
      .get<FigureListResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/figures`,
      )
      .pipe(
        map(
          (response) =>
            response.figures,
        ),
      );
  }

  create(
    campaignId: number,
    payload: CreateCampaignFigurePayload,
  ): Observable<CampaignFigure> {
    return this.http
      .post<FigureResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/figures`,
        payload,
      )
      .pipe(
        map(
          (response) =>
            response.figure,
        ),
      );
  }

  update(
    figureId: number,
    payload: UpdateCampaignFigurePayload,
  ): Observable<CampaignFigure> {
    return this.http
      .patch<FigureResponse>(
        `${this.apiUrl}/figures/${figureId}`,
        payload,
      )
      .pipe(
        map(
          (response) =>
            response.figure,
        ),
      );
  }
}

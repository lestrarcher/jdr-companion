import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';

export type QuestStatus =
  | 'active'
  | 'completed'
  | 'hidden';

export interface CampaignQuest {
  id: number;
  campaignId: number;
  title: string;
  objective: string;
  category: string;
  status: QuestStatus;
  displayOrder: number;
  createdAt: string;
  updatedAt: string | null;
}

export interface CreateQuestPayload {
  title: string;
  objective: string;
  category: string;
}

export interface UpdateQuestPayload {
  title?: string;
  objective?: string;
  category?: string;
  status?: QuestStatus;
  displayOrder?: number;
}

interface QuestListResponse {
  quests: CampaignQuest[];
}

interface QuestResponse {
  quest: CampaignQuest;
}

@Injectable({
  providedIn: 'root',
})
export class QuestApiService {
  private readonly http = inject(HttpClient);

  private readonly apiUrl = '/api';

  list(
    campaignId: number,
  ): Observable<CampaignQuest[]> {
    return this.http
      .get<QuestListResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/quests`,
      )
      .pipe(
        map((response) => response.quests),
      );
  }

  create(
    campaignId: number,
    payload: CreateQuestPayload,
  ): Observable<CampaignQuest> {
    return this.http
      .post<QuestResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/quests`,
        payload,
      )
      .pipe(
        map((response) => response.quest),
      );
  }

  update(
    questId: number,
    payload: UpdateQuestPayload,
  ): Observable<CampaignQuest> {
    return this.http
      .patch<QuestResponse>(
        `${this.apiUrl}/quests/${questId}`,
        payload,
      )
      .pipe(
        map((response) => response.quest),
      );
  }
}

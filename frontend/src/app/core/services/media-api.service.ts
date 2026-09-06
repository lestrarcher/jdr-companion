import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';

import {
  Observable,
  map,
} from 'rxjs';

export interface CampaignMedia {
  id: number;
  title: string | null;
  originalName: string;
  mimeType: string;
  size: number;
  usage: MediaUsage;
  url: string;
  createdAt: string;
}

export type MediaUsage =
  | 'scene'
  | 'portrait';

interface MediaListApiResponse {
  media: CampaignMedia[];
}

interface MediaUploadApiResponse {
  media: CampaignMedia;
}

@Injectable({
  providedIn: 'root',
})
export class MediaApiService {
  private readonly http =
    inject(HttpClient);

  private readonly apiUrl = '/api';

  list(
    campaignId: number,
    usage: MediaUsage = 'scene',
  ): Observable<CampaignMedia[]> {
    return this.http
      .get<MediaListApiResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/media?usage=${usage}`,
      )
      .pipe(
        map((response) => response.media),
      );
  }

  upload(
    campaignId: number,
    file: File,
    title?: string,
    usage: MediaUsage = 'scene',
  ): Observable<CampaignMedia> {
    const formData = new FormData();

    formData.append(
      'file',
      file,
      file.name,
    );

    const normalizedTitle =
      title?.trim();

    if (normalizedTitle) {
      formData.append(
        'title',
        normalizedTitle,
      );
    }

    formData.append('usage', usage);

    return this.http
      .post<MediaUploadApiResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/media`,
        formData,
      )
      .pipe(
        map((response) => response.media),
      );
  }

  remove(
    campaignId: number,
    mediaId: number,
  ): Observable<void> {
    return this.http.delete<void>(
      `${this.apiUrl}/campaigns/${campaignId}/media/${mediaId}`,
    );
  }
}

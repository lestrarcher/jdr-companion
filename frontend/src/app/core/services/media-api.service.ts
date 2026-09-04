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
  url: string;
  createdAt: string;
}

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
  ): Observable<CampaignMedia[]> {
    return this.http
      .get<MediaListApiResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/media`,
      )
      .pipe(
        map((response) => response.media),
      );
  }

  upload(
    campaignId: number,
    file: File,
    title?: string,
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

    return this.http
      .post<MediaUploadApiResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/media`,
        formData,
      )
      .pipe(
        map((response) => response.media),
      );
  }
}

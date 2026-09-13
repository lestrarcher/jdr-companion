import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

export interface Weather {
  id: number;
  key: string;
  label: string;
  imageUrl: string | null;
  alt: string | null;
  system: boolean;
  campaignId: number | null;
}

interface WeatherListResponse {
  weathers: Weather[];
}

@Injectable({
  providedIn: 'root',
})
export class WeatherApiService {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = '/api';

  list(
    campaignId: number,
  ): Observable<Weather[]> {
    return this.http
      .get<WeatherListResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/weathers`,
      )
      .pipe(
        map((response) => response.weathers),
      );
  }
}

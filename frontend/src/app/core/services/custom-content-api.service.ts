import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { map } from 'rxjs';
import { BackgroundOptions, BackgroundPayload, CustomBackground } from '@core/models/custom-background.model';
import { CreateCustomResourcePayload, CustomFeature, CustomFeatureRule, CustomResource, CustomResourceRule, UpdateCustomResourcePayload } from '@core/models/custom-content.model';

@Injectable({ providedIn: 'root' })
export class CustomContentApiService {
  private readonly http = inject(HttpClient);
  private readonly base = '/api/reference/custom';

  backgrounds() {
    return this.http.get<{ backgrounds: CustomBackground[] }>(`${this.base}/backgrounds`, { withCredentials: true }).pipe(map(r => r.backgrounds));
  }
  backgroundOptions() {
    return this.http.get<BackgroundOptions>(`${this.base}/backgrounds/options`, { withCredentials: true });
  }
  saveBackground(id: number | null, payload: Partial<BackgroundPayload>) {
    const request = id === null
      ? this.http.post<{ background: CustomBackground }>(`${this.base}/backgrounds`, payload, { withCredentials: true })
      : this.http.patch<{ background: CustomBackground }>(`${this.base}/backgrounds/${id}`, payload, { withCredentials: true });
    return request.pipe(map(r => r.background));
  }
  deleteBackground(id: number) {
    return this.http.delete<void>(`${this.base}/backgrounds/${id}`, { withCredentials: true });
  }

  features() {
    return this.http.get<{ features: CustomFeature[] }>(`${this.base}/features`, { withCredentials: true })
      .pipe(map(response => response.features));
  }

  resources() {
    return this.http.get<{ resources: CustomResource[] }>(`${this.base}/resources`, { withCredentials: true })
      .pipe(map(response => response.resources));
  }

  createResource(payload: CreateCustomResourcePayload) {
    return this.http.post<{ resource: CustomResource }>(`${this.base}/resources`, payload, { withCredentials: true })
      .pipe(map(response => response.resource));
  }

  updateResource(id: number, payload: UpdateCustomResourcePayload) {
    return this.http.patch<{ resource: CustomResource }>(`${this.base}/resources/${id}`, payload, { withCredentials: true })
      .pipe(map(response => response.resource));
  }

  deleteResource(id: number) {
    return this.http.delete<void>(`${this.base}/resources/${id}`, { withCredentials: true });
  }

  featureRules() {
    return this.http.get<{ rules: CustomFeatureRule[] }>(`${this.base}/feature-rules`, { withCredentials: true })
      .pipe(map(response => response.rules));
  }

  resourceRules() {
    return this.http.get<{ rules: CustomResourceRule[] }>(`${this.base}/resource-rules`, { withCredentials: true })
      .pipe(map(response => response.rules));
  }
}

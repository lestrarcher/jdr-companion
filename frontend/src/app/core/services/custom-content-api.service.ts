import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { map } from 'rxjs';
import { CustomFeature, CustomFeatureRule, CustomResource, CustomResourceRule } from '@core/models/custom-content.model';

@Injectable({ providedIn: 'root' })
export class CustomContentApiService {
  private readonly http = inject(HttpClient);
  private readonly base = '/api/reference/custom';

  features() {
    return this.http.get<{ features: CustomFeature[] }>(`${this.base}/features`, { withCredentials: true })
      .pipe(map(response => response.features));
  }

  resources() {
    return this.http.get<{ resources: CustomResource[] }>(`${this.base}/resources`, { withCredentials: true })
      .pipe(map(response => response.resources));
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

import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';

export interface FeatureAssignment {
  characterId: number;
  characterName: string;
  campaignId: number;
}

@Injectable({ providedIn: 'root' })
export class CharacterFeatureAssignmentApiService {
  private readonly http = inject(HttpClient);
  private base(featureId: number): string {
    return `/api/reference/features/${featureId}/assignments`;
  }
  list(featureId: number): Observable<{ assignments: FeatureAssignment[] }> {
    return this.http.get<{ assignments: FeatureAssignment[] }>(this.base(featureId));
  }
  assign(featureId: number, characterId: number): Observable<unknown> {
    return this.http.post(this.base(featureId), { characterId });
  }
  remove(featureId: number, characterId: number): Observable<unknown> {
    return this.http.delete(`${this.base(featureId)}/${characterId}`);
  }
}

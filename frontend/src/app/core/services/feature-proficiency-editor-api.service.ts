import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { map } from 'rxjs';

export interface ProficiencyDefinition { id: number; name: string; slug: string; type: string; }
export interface FeatureProficiencyRule { id: number; label: string; choiceCount: number; options: ProficiencyDefinition[]; }
export interface FeatureProficiencyPayload { id?: number; label: string; choiceCount: number; optionIds: number[]; }

@Injectable({providedIn: 'root'})
export class FeatureProficiencyEditorApiService {
  private readonly http = inject(HttpClient);
  private readonly base = '/api/reference/custom/feature-proficiencies';
  definitions() { return this.http.get<{definitions: ProficiencyDefinition[]}>(`${this.base}/definitions`).pipe(map(r => r.definitions)); }
  rules(featureId: number) { return this.http.get<{rules: FeatureProficiencyRule[]}>(`${this.base}/${featureId}`).pipe(map(r => r.rules)); }
  save(featureId: number, payload: FeatureProficiencyPayload) { return this.http.post<{rule: FeatureProficiencyRule}>(`${this.base}/${featureId}`, payload); }
}

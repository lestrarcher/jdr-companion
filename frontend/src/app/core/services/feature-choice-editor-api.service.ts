import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';

export interface CustomChoiceRow {
  key: string;
  name: string;
  uniqueness: 'group' | 'character' | 'none';
  replacement: 'none' | 'class-level' | 'grant-level';
  rule_id: number;
  choice_count: number;
  options: { key: string; featureId: number; name: string }[];
}

export interface CustomChoicePayload {
  ruleId: number;
  name: string;
  choiceCount: number;
  uniqueness: CustomChoiceRow['uniqueness'];
  replacement: CustomChoiceRow['replacement'];
  options: number[];
}

@Injectable({ providedIn: 'root' })
export class FeatureChoiceEditorApiService {
  private readonly http = inject(HttpClient);
  private url(id: number): string { return `/api/reference/custom/features/${id}/choices`; }
  get(id: number) { return this.http.get<{ choices: CustomChoiceRow[] }>(this.url(id)); }
  save(id: number, payload: CustomChoicePayload) { return this.http.post<{ choice: unknown }>(this.url(id), payload); }
}

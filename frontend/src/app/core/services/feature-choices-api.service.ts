import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

export interface FeatureChoiceSelection { grantKey: string; optionKey: string; slot: number; replacesKey?: string; }
export interface FeatureChoiceOption { key: string; featureKey: string; name: string; description: string | null; source_code?: string; prerequisites: Record<string, unknown>; reasons: string[]; alreadyAcquired?: boolean; }
export interface FeatureChoiceGrant {
  key: string; name: string; classKey: string | null; classLevel: number | null; unlockLevel: number; choice_count: number;
  newAtLevel?: boolean; replacementAvailable: boolean; remaining: number; options: FeatureChoiceOption[];
  selected: { key: string; slot: number; optionKey: string; valid: boolean }[];
}
export interface FeatureChoiceView {
  fingerprint: string; grants: FeatureChoiceGrant[]; missing: string[]; issues: unknown[];
  history: { key: string; grant_key: string; option_key: string; slot: number; active: boolean; acquired_position: number; reason: string | null }[];
  legacyAttributions?: { group_key: string; name: string }[];
}
export interface FeatureChoiceRepair { fingerprint: string; reason: string; selections: FeatureChoiceSelection[]; }
export type FeatureChoiceLoader = (classKey: string, subclassKey: string | null) => Observable<FeatureChoiceView>;

@Injectable({ providedIn: 'root' })
export class FeatureChoicesApi {
  private readonly http = inject(HttpClient);
  private gm(campaignId: number, characterId: number) { return `/api/campaigns/${campaignId}/characters/${characterId}/feature-choices`; }
  get(campaignId: number, characterId: number) { return this.http.get<FeatureChoiceView>(this.gm(campaignId, characterId)); }
  preview(campaignId: number, characterId: number, classKey: string, subclassKey: string | null) {
    return this.http.post<FeatureChoiceView>(`${this.gm(campaignId, characterId)}/preview`, { classKey, subclassKey });
  }
  playerPreview(token: string, classKey: string, subclassKey: string | null) {
    return this.http.post<FeatureChoiceView>(`/api/public/characters/${token}/feature-choices/preview`, { classKey, subclassKey });
  }
  repair(campaignId: number, characterId: number, payload: FeatureChoiceRepair) {
    return this.http.post<FeatureChoiceView>(this.gm(campaignId, characterId), payload);
  }
}

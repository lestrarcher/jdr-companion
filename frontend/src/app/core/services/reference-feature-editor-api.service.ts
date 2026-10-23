import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { REFERENCE_EDITOR_SCOPE } from './reference-editor-context';
import type { FeatureListResponse, ResourceListResponse, FeatureRuleListResponse, ResourceRuleListResponse, SaveFeaturePayload, SaveResourcePayload, CreateFeatureRulePayload, UpdateFeatureRulePayload, CreateResourceRulePayload, UpdateResourceRulePayload, CharacterFeatureDefinition, TrackableResourceDefinition, CharacterFeatureRule, TrackableResourceRule } from './legacy-character-feature-api.service';

@Injectable()
export class ReferenceFeatureEditorApiService {
  private readonly http=inject(HttpClient);
  readonly scope=inject(REFERENCE_EDITOR_SCOPE);
  private url(category: string) { return `/api/reference/${this.scope}/${category}/editor`; }
  getFeatureSpellGrantOptions(featureId: number) {
    return this.http.get<{ classes: { id: number; name: string }[]; spells: { id: number; name: string; level: number }[] }>(`/api/reference/${this.scope}/features/${featureId}/spell-grants/options`);
  }
  getFeatureSpellGrants(featureId: number) {
    return this.http.get<{ grants: FeatureSpellGrant[] }>(`/api/reference/${this.scope}/features/${featureId}/spell-grants`);
  }
  addFeatureSpellGrant(featureId: number, payload: FeatureSpellGrantPayload) {
    return this.http.post<{ grants: FeatureSpellGrant[] }>(`/api/reference/${this.scope}/features/${featureId}/spell-grants`, payload);
  }
  deleteFeatureSpellGrant(featureId: number, grantId: number) {
    return this.http.delete<{ grants: FeatureSpellGrant[] }>(`/api/reference/${this.scope}/features/${featureId}/spell-grants/${grantId}`);
  }
  getFeatures() { return this.http.get<FeatureListResponse>(this.url('features')); }
  deletePersonalFeature(id: number) { return this.http.delete<void>(`/api/reference/custom/features/${id}`); }
  deletePersonalResource(id: number) { return this.http.delete<void>(`/api/reference/custom/resources/${id}`); }
  getResources() { return this.http.get<ResourceListResponse>(this.url('resources')); }
  createFeature(payload: SaveFeaturePayload) {
    const {custom: _origin,...data}=payload;
    return this.http.post<{feature: CharacterFeatureDefinition}>(this.url('features'),data);
  }
  updateFeature(id: number,payload: Partial<SaveFeaturePayload>) {
    const {custom: _origin,slug: _slug,...data}=payload;
    return this.http.patch<{feature: CharacterFeatureDefinition}>(`${this.url('features')}/${id}`,data);
  }
  createResource(payload: SaveResourcePayload) {
    const {custom: _origin,...data}=payload;
    return this.http.post<{resource: TrackableResourceDefinition}>(this.url('resources'),data);
  }
  updateResource(id: number,payload: Partial<SaveResourcePayload>) {
    const {custom: _origin,slug: _slug,...data}=payload;
    return this.http.patch<{resource: TrackableResourceDefinition}>(`${this.url('resources')}/${id}`,data);
  }
  getRules() { return this.http.get<FeatureRuleListResponse>(this.url('feature-rules')); }
  createRule(payload: CreateFeatureRulePayload) {
    // The old form carries a technical level for progression sources. Only the threshold is meaningful.
    const data=payload.sourceType==='progression'?{...payload,unlockLevel:null}:payload;
    return this.http.post<{rule: CharacterFeatureRule}>(this.url('feature-rules'),data);
  }
  updateRule(id: number,payload: UpdateFeatureRulePayload) { return this.http.patch<{rule: CharacterFeatureRule}>(`${this.url('feature-rules')}/${id}`,payload); }
  deleteRule(id: number) { return this.http.delete<void>(`${this.url('feature-rules')}/${id}`); }
  getResourceRules(id: number) { return this.http.get<ResourceRuleListResponse>(this.url('resource-rules'),{params:{resourceId:id}}); }
  createResourceRule(id: number,payload: CreateResourceRulePayload) { return this.http.post<{rule: TrackableResourceRule}>(this.url('resource-rules'),{...payload,resourceDefinitionId:id}); }
  updateResourceRule(id: number,payload: UpdateResourceRulePayload) { return this.http.patch<{rule: TrackableResourceRule}>(`${this.url('resource-rules')}/${id}`,payload); }
  deleteResourceRule(id: number) { return this.http.delete<void>(`${this.url('resource-rules')}/${id}`); }
}

export interface FeatureSpellGrant {
  id: number;
  mode: 'fixed' | 'choice';
  spellId: number | null;
  spellName: string | null;
  spellLevel: number | null;
  classId: number | null;
  className: string | null;
  schools: string[];
  unlockLevel: number;
  castingAbility: string | null;
}
export type FeatureSpellGrantPayload =
  | { mode: 'fixed'; spellId: number; unlockLevel: number; castingAbility: string | null }
  | { mode: 'choice'; spellLevel: number; classId: number | null; schools: string[]; unlockLevel: number; castingAbility: string | null };

import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';

export type FeatureActivationType =
  | 'passive'
  | 'action'
  | 'bonus_action'
  | 'reaction'
  | 'free_action'
  | 'special';

export type FeatureSourceType = 'class' | 'subclass' | 'race' | 'feat';

export interface EnumChoice<T extends string = string> {
  value: T;
  label: string;
}

export interface AbilityChoice extends EnumChoice {
  abbreviation: string;
}

export interface TrackableResourceDefinition {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  rechargeType: string;
  maximumType: string;
  baseMaximum: number;
  multiplier: number;
  minimumMaximum: number;
  scalingAbility: string | null;
  custom: boolean;
}

export interface CharacterFeatureDefinition {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  activationType: FeatureActivationType;
  activationLabel: string;
  visible: boolean;
  custom: boolean;
  resourceDefinition: TrackableResourceDefinition | null;
}

export interface CharacterFeatureRule {
  id: number;
  feature: {
    id: number;
    slug: string;
    name: string;
    activationType: FeatureActivationType;
    hasResource: boolean;
  };
  sourceType: FeatureSourceType;
  sourceId: number;
  sourceName: string;
  unlockLevel: number;
  displayOrder: number;
}

export interface FeatureListResponse {
  features: CharacterFeatureDefinition[];
  resources: TrackableResourceDefinition[];
  activationTypes: EnumChoice<FeatureActivationType>[];
}

export interface ResourceListResponse {
  resources: TrackableResourceDefinition[];
  rechargeTypes: EnumChoice[];
  maximumTypes: EnumChoice[];
  abilities: AbilityChoice[];
}

export interface FeatureRuleListResponse {
  rules: CharacterFeatureRule[];
}

export interface SaveFeaturePayload {
  slug: string;
  name: string;
  description?: string | null;
  activationType: FeatureActivationType;
  resourceDefinitionId?: number | null;
  visible: boolean;
  custom?: boolean;
}

export interface SaveResourcePayload {
  slug: string;
  name: string;
  description?: string | null;
  rechargeType: string;
  maximumType: string;
  baseMaximum: number;
  multiplier: number;
  minimumMaximum: number;
  scalingAbility?: string | null;
  custom?: boolean;
}

export interface CreateFeatureRulePayload {
  featureDefinitionId: number;
  sourceType: FeatureSourceType;
  sourceId: number;
  unlockLevel: number;
  displayOrder: number;
}

export interface UpdateFeatureRulePayload {
  unlockLevel?: number;
  displayOrder?: number;
}

@Injectable({ providedIn: 'root' })
export class CharacterFeatureApiService {
  private readonly http = inject(HttpClient);

  getFeatures(): Observable<FeatureListResponse> {
    return this.http.get<FeatureListResponse>('/api/dnd/features');
  }

  createFeature(
    payload: SaveFeaturePayload,
  ): Observable<{ feature: CharacterFeatureDefinition }> {
    return this.http.post<{ feature: CharacterFeatureDefinition }>(
      '/api/dnd/features',
      payload,
    );
  }

  updateFeature(
    featureId: number,
    payload: Partial<SaveFeaturePayload>,
  ): Observable<{ feature: CharacterFeatureDefinition }> {
    return this.http.patch<{ feature: CharacterFeatureDefinition }>(
      `/api/dnd/features/${featureId}`,
      payload,
    );
  }

  getResources(): Observable<ResourceListResponse> {
    return this.http.get<ResourceListResponse>('/api/dnd/resources');
  }

  createResource(
    payload: SaveResourcePayload,
  ): Observable<{ resource: TrackableResourceDefinition }> {
    return this.http.post<{ resource: TrackableResourceDefinition }>(
      '/api/dnd/resources',
      payload,
    );
  }

  updateResource(
    resourceId: number,
    payload: Partial<SaveResourcePayload>,
  ): Observable<{ resource: TrackableResourceDefinition }> {
    return this.http.patch<{ resource: TrackableResourceDefinition }>(
      `/api/dnd/resources/${resourceId}`,
      payload,
    );
  }

  getRules(): Observable<FeatureRuleListResponse> {
    return this.http.get<FeatureRuleListResponse>('/api/dnd/feature-rules');
  }

  createRule(
    payload: CreateFeatureRulePayload,
  ): Observable<{ rule: CharacterFeatureRule }> {
    return this.http.post<{ rule: CharacterFeatureRule }>(
      '/api/dnd/feature-rules',
      payload,
    );
  }

  updateRule(
    ruleId: number,
    payload: UpdateFeatureRulePayload,
  ): Observable<{ rule: CharacterFeatureRule }> {
    return this.http.patch<{ rule: CharacterFeatureRule }>(
      `/api/dnd/feature-rules/${ruleId}`,
      payload,
    );
  }

  deleteRule(ruleId: number): Observable<void> {
    return this.http.delete<void>(`/api/dnd/feature-rules/${ruleId}`);
  }
}

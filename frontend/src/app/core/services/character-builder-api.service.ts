import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { AbilityKey } from './dnd-reference-api.service';

export interface RacialAbilityChoicePayload {
  modifierId: number;
  ability: AbilityKey;
}

export interface RacialFeatChoicePayload {
  featId: number;
  ability: AbilityKey | null;
}


export interface TrainingPayload {
  proficiencyChoices: { ruleId: number; proficiencyIds: number[] }[];
  languageChoices: { sourceKey: string; languageSlugs: string[] }[];
  substitutions: { ruleId: number; originalProficiencyId: number; replacementProficiencyId: number }[];
  expertiseChoices: { ruleId: number; proficiencyIds: number[] }[];
  manualGrants: { kind: 'PROFICIENCY' | 'EXPERTISE' | 'LANGUAGE'; proficiencyId?: number; languageSlug?: string; reason: string }[];
}

export interface BuilderSelection {
  raceId: number;
  classId: number;
  backgroundId: number | null;
  subclassId: number | null;
  level: 1;
  racialFeatChoices: RacialFeatChoicePayload[];
  training: TrainingPayload;
}

export interface BuilderOptions {
  proficiencyRules: {
    ruleId: number; key: string; label: string; sourceType: string;
    choiceCount: number; optionIds: number[];
  }[];
  languageRules: {
    sourceKey: string; sourceType: string; automaticSlugs: string[];
    choiceCount: number; optionSlugs: string[];
  }[];
  substitutions: {
    ruleId: number; originalProficiencyId: number; optionIds: number[];
  }[];
  expertiseRules: {
    ruleId: number; key: string; label: string;
    choiceCount: number; optionIds: number[];
  }[];
  resolved: {
    proficiencies: { id: number; slug: string; name: string; grants: {ruleId: number; label: string; sourceType: string}[]; manualGrantIds: number[]; substitutions: unknown[] }[];
    languages: { id: number; slug: string; name: string; sources: string[] }[];
    expertises: { id: number; slug: string; name: string }[];
    manualGrants: unknown[];
  };
  notImplemented: string[];
}

export interface CreateStructuredCharacterPayload {
  slug: string;
  name: string;
  playerName: string | null;
  type: 'player' | 'npc';
  raceId: number;
  classId: number;
  subclassId: number | null;
  abilities: Record<AbilityKey, number>;
  racialAbilityChoices: RacialAbilityChoicePayload[];
  racialFeatChoices: RacialFeatChoicePayload[];
  backgroundId?: number | null;
  training: TrainingPayload;
}

export interface BuiltAbility {
  ability: AbilityKey;
  label: string;
  abbreviation: string;
  baseValue: number;
  effectiveValue: number;
  maximumValue: number;
  modifier: number;
}

export interface BuiltResource {
  slug: string;
  name: string;
  maximum: number;
  rechargeType: 'none' | 'short-rest' | 'long-rest';
}
export interface BuiltClassLevel {
  position: number;
  classId: number;
  className: string;
  subclassId: number | null;
  subclassName: string | null;
}

export interface StructuredCharacterResponse {
  id: number;
  campaignId: number;
  slug: string;
  name: string;
  playerName: string | null;
  type: 'player' | 'npc';
  race: {
    id: number | null;
    name: string | null;
  };
  totalLevel: number;
  proficiencyBonus: number;
  classLevels: BuiltClassLevel[];
  abilities: BuiltAbility[];
  resources: BuiltResource[];
}

interface CreateStructuredCharacterResponse {
  character: StructuredCharacterResponse;
}

@Injectable({ providedIn: 'root' })
export class CharacterBuilderApiService {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = '/api';

  options(campaignId: number, selection: BuilderSelection): Observable<BuilderOptions> {
    return this.http.get<BuilderOptions>(
      `${this.apiUrl}/campaigns/${campaignId}/characters/build/options`,
      { params: { selection: JSON.stringify(selection) } },
    );
  }

  create(
    campaignId: number,
    payload: CreateStructuredCharacterPayload,
  ): Observable<CreateStructuredCharacterResponse> {
    return this.http.post<CreateStructuredCharacterResponse>(
      `${this.apiUrl}/campaigns/${campaignId}/characters/build`,
      payload,
    );
  }
}

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

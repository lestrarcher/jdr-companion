import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable, map } from 'rxjs';
import { AbilityKey } from './dnd-reference-api.service';

export interface CreateCharacterPayload {
  slug: string;
  name: string;
  playerName: string | null;
  type: 'player' | 'npc';
  definition: Record<string, unknown>;
}

export interface CharacterRaceSummary {
  id: number;
  slug?: string;
  name: string;
}

export interface CharacterClassLevel {
  position: number;
  classId: number;
  classSlug?: string;
  className: string;
  subclassId: number | null;
  subclassSlug?: string | null;
  subclassName: string | null;
}

export interface CharacterClassSummary {
  classId: number;
  classSlug: string;
  className: string;
  level: number;
  subclassId: number | null;
  subclassSlug: string | null;
  subclassName: string | null;
}

export interface CharacterAbilityScore {
  ability: AbilityKey;
  label: string;
  abbreviation: string;
  baseValue: number;
  effectiveValue: number;
  maximumValue: number;
  modifier: number;
}

export interface CharacterFeatSummary {
  id: number;
  featId: number;
  slug: string;
  name: string;
  description: string | null;
  chosenAbility: AbilityKey | null;
  abilityIncrease: number;
  acquiredAtLevel: number | null;
}

export interface CharacterFeatureSummary {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  activationType: string;
  visible: boolean;
  custom: boolean;
  sourceType: 'class' | 'subclass' | 'race' | 'feat';
  sourceId: number;
  sourceName: string;
  unlockLevel: number;
  displayOrder: number;
  resource: {
    id: number;
    slug: string;
    name: string;
  } | null;
}

export interface CharacterResourceSummary {
  slug: string;
  name: string;
  maximum: number;
  rechargeType: string;
}

export interface CharacterApiResponse {
  id: number;
  campaignId: number;
  slug: string;
  name: string;
  playerName: string | null;
  type: 'player' | 'npc';
  race: CharacterRaceSummary | null;
  totalLevel: number;
  proficiencyBonus: number;
  classLevels: CharacterClassLevel[];
  definition: Record<string, unknown>;
}

export interface CharacterProfile extends CharacterApiResponse {
  classSummary: CharacterClassSummary[];
  abilities: CharacterAbilityScore[];
  feats: CharacterFeatSummary[];
  features: CharacterFeatureSummary[];
  resources: CharacterResourceSummary[];
}

export interface LevelUpSubclassOption {
  id: number;
  slug: string;
  name: string;
}

export interface LevelUpClassOption {
  id: number;
  slug: string;
  name: string;
  currentLevel: number;
  nextLevel: number;
  subclassSelectionLevel: number;
  subclassRequired: boolean;
  currentSubclass: LevelUpSubclassOption | null;
  subclasses: LevelUpSubclassOption[];
  advancementRequired: boolean;
}

export interface LevelUpOptions {
  canLevelUp: boolean;
  currentTotalLevel: number;
  nextTotalLevel: number;
  classes: LevelUpClassOption[];
}

export interface AbilityAdvancementPayload {
  type: 'ability';
  increases: Array<{
    ability: AbilityKey;
    value: 1 | 2;
  }>;
}

export interface FeatAdvancementPayload {
  type: 'feat';
  featId: number;
  ability: AbilityKey | null;
}

export type LevelAdvancementPayload =
  | AbilityAdvancementPayload
  | FeatAdvancementPayload;

export interface LevelUpPayload {
  classId: number;
  subclassId: number | null;
  advancement: LevelAdvancementPayload | null;
}

export interface LevelUpResponse {
  message: string;
  level: CharacterClassLevel;
  character: CharacterProfile;
}

interface CharacterCreateApiResponse {
  character: CharacterApiResponse;
}

interface CharacterListApiResponse {
  characters: CharacterApiResponse[];
}

interface CharacterProfileApiResponse {
  character: CharacterProfile;
}

@Injectable({ providedIn: 'root' })
export class CharacterApiService {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = '/api';

  create(
    campaignId: number,
    payload: CreateCharacterPayload,
  ): Observable<CharacterApiResponse> {
    return this.http
      .post<CharacterCreateApiResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/characters`,
        payload,
      )
      .pipe(map(response => response.character));
  }

  list(campaignId: number): Observable<CharacterApiResponse[]> {
    return this.http
      .get<CharacterListApiResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/characters`,
      )
      .pipe(map(response => response.characters));
  }

  getProfile(
    campaignId: number,
    characterId: number,
  ): Observable<CharacterProfile> {
    return this.http
      .get<CharacterProfileApiResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/characters/${characterId}`,
      )
      .pipe(map(response => response.character));
  }

  getLevelUpOptions(
    campaignId: number,
    characterId: number,
  ): Observable<LevelUpOptions> {
    return this.http.get<LevelUpOptions>(
      `${this.apiUrl}/campaigns/${campaignId}/characters/${characterId}/level-up/options`,
    );
  }

  levelUp(
    campaignId: number,
    characterId: number,
    payload: LevelUpPayload,
  ): Observable<LevelUpResponse> {
    return this.http.post<LevelUpResponse>(
      `${this.apiUrl}/campaigns/${campaignId}/characters/${characterId}/level-up`,
      payload,
    );
  }
}

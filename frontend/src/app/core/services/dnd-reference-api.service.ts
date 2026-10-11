import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';

export type AbilityKey =
  | 'strength'
  | 'dexterity'
  | 'constitution'
  | 'intelligence'
  | 'wisdom'
  | 'charisma';

export type SpellcastingProgression =
  | 'none'
  | 'full'
  | 'half'
  | 'artificer'
  | 'third'
  | 'pact';

export interface AbilityReference {
  value: AbilityKey;
  label: string;
  abbreviation: string;
}

export interface SpellcastingProgressionChoice {
  value: SpellcastingProgression;
  label: string;
}

export interface RaceAbilityModifierReference {
  id: number;
  sourceRaceId: number;
  sourceRaceName?: string;
  ability: AbilityKey | null;
  abilityLabel?: string | null;
  abilityAbbreviation?: string | null;
  value: number;
  choiceKey: string | null;
  requiresChoice: boolean;
}

export interface RaceParentReference {
  id: number;
  name: string;
}

export interface RaceMetadataReference {
  sizeOptions: string[] | null;
  walkingSpeed: number | null;
  movementSpeeds: Partial<Record<'swim' | 'fly' | 'climb', number | null>> | null;
  languages: string[] | null;
  languageChoiceCount: number | null;
  senses: Partial<Record<'darkvision' | 'blindsight' | 'tremorsense' | 'truesight', number | null>> | null;
  damageResistances: string[] | null;
  damageImmunities: string[] | null;
  conditionImmunities: string[] | null;
}

export interface EffectiveRaceMetadataReference {
  sizeOptions: string[];
  walkingSpeed: number | null;
  movementSpeeds: Partial<Record<'swim' | 'fly' | 'climb', number>>;
  languages: string[];
  languageChoiceCount: number;
  senses: Partial<Record<'darkvision' | 'blindsight' | 'tremorsense' | 'truesight', number>>;
  damageResistances: string[];
  damageImmunities: string[];
  conditionImmunities: string[];
}

export interface RaceReference {
  id: number;
  slug: string;
  name: string;
  selectable: boolean;
  metadata?: RaceMetadataReference;
  effectiveMetadata?: EffectiveRaceMetadataReference;
  description?: string | null;
  custom?: boolean;
  parentRaceId?: number | null;
  parentRace?: RaceParentReference | null;
  featChoiceCount: number;
  inheritedFeatChoiceCount?: number;
  abilityModifiers: RaceAbilityModifierReference[];
  inheritedAbilityModifiers?: RaceAbilityModifierReference[];
}

export interface ClassReference {
  id: number;
  slug: string;
  name: string;
  hitDie: number;
  subclassSelectionLevel: number;
  spellcastingProgression: SpellcastingProgression;
  spellcastingProgressionLabel?: string;
  description?: string | null;
  custom?: boolean;
}

export interface SubclassReference {
  id: number;
  classId: number;
  className?: string;
  slug: string;
  name: string;
  description?: string | null;
  spellcastingProgression: SpellcastingProgression | null;
  custom?: boolean;
}

export interface FeatReference {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  repeatable: boolean;
  requiresAbilityChoice: boolean;
  chosenAbilityIncrease: number;
  allowedAbilities: AbilityKey[];
  custom: boolean;
}

export interface ProgressionStageReference {
  id: number;
  label: string;
  description: string | null;
  minimumValue: number;
  maximumValue: number | null;
  iconUrl: string | null;
  displayOrder: number;
}

export interface ProgressionReference {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  minimumValue: number;
  maximumValue: number | null;
  accentColor: string | null;
  gainLabel: string | null;
  spendLabel: string | null;
  custom: boolean;
  bulkAdjustmentEnabled: boolean;
  stages: ProgressionStageReference[];
  adjustmentRules: ProgressionAdjustmentRuleReference[];
}

export interface ProgressionAdjustmentRuleReference {
  id: number;
  direction: 'gain' | 'loss';
  triggerType: string | null;
  description: string;
  adjustmentLabel: string;
  displayOrder: number;
}

export interface DndReferenceResponse {
  abilities: AbilityReference[];
  races: RaceReference[];
  classes: ClassReference[];
  subclasses: SubclassReference[];
  feats: FeatReference[];
}

export interface ProgressionListResponse {
  progressions: ProgressionReference[];
}

@Injectable({ providedIn: 'root' })
export class DndReferenceApiService {
  private readonly http = inject(HttpClient);

  getCampaignReference(campaignId: number): Observable<DndReferenceResponse> {
    return this.http.get<DndReferenceResponse>(`/api/campaigns/${campaignId}/dnd/reference`);
  }

  getCampaignProgressions(campaignId: number): Observable<ProgressionListResponse> {
    return this.http.get<ProgressionListResponse>(`/api/campaigns/${campaignId}/dnd/progressions`);
  }
}

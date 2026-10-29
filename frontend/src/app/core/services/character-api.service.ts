import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable, map } from 'rxjs';
import { AbilityKey, AbilityReference, FeatReference } from './dnd-reference-api.service';
import type { Spell } from '../models/spell.model';
import type { SpellInitializationPreview, SpellInitializationPayload, SpellInitializationResult } from '../models/spell-initialization.model';

export type HitPointGainMethod =
  | 'first_level'
  | 'average'
  | 'rolled'
  | 'manual';

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
  hitDie?: number;
  subclassId: number | null;
  subclassSlug?: string | null;
  subclassName: string | null;
  hitPointGain?: number | null;
  hitPointGainMethod?: HitPointGainMethod | null;
  hitPointGainMethodLabel?: string | null;
}

export interface CharacterClassSummary {
  classId: number;
  classSlug: string;
  className: string;
  level: number;
  hitDie: number;
  subclassId: number | null;
  subclassSlug: string | null;
  subclassName: string | null;
}

export interface CharacterHitPoints {
  maximumValue: number | null;
  baseValue: number;
  constitutionModifier: number;
  constitutionBonus: number;
  complete: boolean;
  missingLevelPositions: number[];
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

export interface CharacterProgressionSummary {
  id: number;
  definitionId: number;
  slug: string;
  name: string;
  description: string | null;
  minimumValue: number;
  maximumValue: number | null;
  accentColor: string | null;
  gainLabel: string | null;
  spendLabel: string | null;
  bulkAdjustmentEnabled: boolean;
  stages: Array<{
    id: number;
    label: string;
    minimumValue: number;
    maximumValue: number | null;
    iconUrl: string | null;
    displayOrder: number;
  }>;
}

export interface CharacterFeatureSummary {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  activationType: string;
  visible: boolean;
  custom: boolean;
  sourceType: 'class' | 'subclass' | 'race' | 'feat' | 'progression';
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
  slotLevel?: number | null;
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
  progressions: CharacterProgressionSummary[];
  definition: Record<string, unknown>;
}

export interface CharacterTraining {
  proficiencies: Array<{ id: number; slug: string; name: string; type: 'SKILL' | 'SAVING_THROW' | 'WEAPON' | 'WEAPON_GROUP' | 'ARMOR' | 'TOOL'; grants: Array<{ruleId: number; key: string; label: string; sourceType: string; sourceId: number; unlockLevel: number; choiceCount: number; acquisitionMode: string; optionIds: number[]}>; manualGrantIds: number[]; substitutions: Array<{ruleId: number; originalProficiencyId: number}> }>;
  languages: Array<{id: number; slug: string; name: string; sources: string[]}>;
  expertises: Array<{id: number; slug: string; name: string; type: string; proficiencyId: number; sources: string[]}>;
}

export interface CharacterProfile extends CharacterApiResponse {
  training?: CharacterTraining;
  hitPoints: CharacterHitPoints;
  classSummary: CharacterClassSummary[];
  abilities: CharacterAbilityScore[];
  feats: CharacterFeatSummary[];
  features: CharacterFeatureSummary[];
  resources: CharacterResourceSummary[];
  actions: CharacterActionSummary[];
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
  hitDie: number;
  averageHitPointGain: number;
  currentLevel: number;
  nextLevel: number;
  subclassSelectionLevel: number;
  subclassRequired: boolean;
  currentSubclass: LevelUpSubclassOption | null;
  subclasses: LevelUpSubclassOption[];
  advancementRequired: boolean;
  eligible: boolean;
  multiclassRequirements: string[];
}

export interface HitPointMethodOption {
  value: Exclude<HitPointGainMethod, 'first_level'>;
  label: string;
}

export interface TrainingChoice {
  key: string;
  kind: 'proficiency' | 'language' | 'expertise';
  ruleId?: number;
  sourceKey?: string;
  choiceCount: number;
  selected: Array<number | string>;
  status: 'complete' | 'missing' | 'invalid';
}

export interface TrainingRule {
  ruleId: number;
  key: string;
  label: string;
  sourceType: string;
  sourceId: number;
  unlockLevel: number;
  choiceCount: number;
  acquisitionMode: string;
  optionIds: number[];
}

export interface TrainingOptions {
  proficiencyRules: TrainingRule[];
  choices: TrainingChoice[];
  definitions: {
    proficiencies: Array<{ id: number; slug: string; name: string; type: string }>;
    languages: Array<{ id?: number; slug: string; name: string }>;
  };
  languageRules: unknown[];
  expertiseRules: unknown[];
  substitutions: unknown[];
  resolved: CharacterTraining;
  notImplemented: unknown[];
}

export interface TrainingChoicesPayload {
  proficiencyChoices?: Array<{ ruleId: number; proficiencyIds: number[] }>;
  languageChoices?: Array<{ sourceKey: string; languageSlugs: string[] }>;
  expertiseChoices?: Array<{ ruleId: number; proficiencyIds: number[] }>;
}

export interface LevelUpOptions {
  spellPreview: LevelUpSpellPreview;
  training?: TrainingOptions;
  abilities: AbilityReference[];
  feats: FeatReference[];
  canLevelUp: boolean;
  currentTotalLevel: number;
  nextTotalLevel: number;
  hitPointMethods: HitPointMethodOption[];
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

export interface CharacterActionSummary {
  slug: string;
  name: string;
  description: string | null;
  handlerType: string;
  requiresPreparation: boolean;
  budget?: number | null;
}

export type LevelAdvancementPayload =
  | AbilityAdvancementPayload
  | FeatAdvancementPayload;

export interface LevelUpHitPointPayload {
  method: Exclude<HitPointGainMethod, 'first_level'>;
  gain?: number;
}

export interface SpellQualificationResponse {
  qualification: {
    acquisitionId: number;
    policyId: number;
    ruleId: number;
    originKnown: boolean;
    decidedById: number;
    reason: string;
    decidedAt: string;
  };
}

export interface LevelUpSpellChoice {
  policyId: number;
  spellId: number;
  ruleId: number | null;
  replacesAcquisitionId?: number;
  replacementConfirmed?: boolean;
}

export type LevelUpSpellPreview =
  | { status: 'selection-required' | 'subclass-required'; policies: [] }
  | { status: 'no-spellcasting' | 'policy-to-configure' | 'policy-ambiguous'; classId: number; classLevel: number; policies: [] }
  | {
      status: 'quota-configuration-required' | 'quota-exceeded' | 'choices-to-review' | 'choices-to-complete' | 'complete';
      policies: [LevelUpSpellPolicyPreview];
    };

export interface LevelUpSpellReplacementPreview {
  maximum: number;
  budgetKey: string;
  confirmationRequired: boolean;
  acquisitions: Array<{
    acquisitionId: number;
    spellId: number;
    spellName: string;
    requiresQualification: boolean;
  }>;
}

export interface LevelUpSpellOpportunityBase {
  policyId: number;
  classId: number;
  classLevel: number;
  key: string;
  remaining: number;
  replacement: LevelUpSpellReplacementPreview;
}

export type LevelUpSpellOpportunity =
  | (LevelUpSpellOpportunityBase & {
      ruleId: null;
      quota: 'cantrips' | 'known' | 'spellbook';
      available: Array<Pick<Spell, 'id' | 'slug' | 'name' | 'level' | 'school' | 'edition' | 'origin'>>;
      replacementAvailable: Array<Pick<Spell, 'id' | 'slug' | 'name' | 'level' | 'school' | 'edition' | 'origin'>>;
    })
  | (LevelUpSpellOpportunityBase & {
      ruleId: number;
      quota: 'cantrips' | 'known' | 'spellbook' | 'free';
      result: 'known' | 'spellbook' | 'cast';
      castingClassId: number;
      available: Spell[];
      replacementAvailable: Spell[];
    });

export interface LevelUpSpellCopyDetails {
  baseCostCp: number;
  baseMinutes: number;
  costCp: number;
  minutes: number;
  source: string;
  adjustment: 'none' | 'rule' | 'gm';
  reason: string;
  confirmed: true;
}

/** Existing acquisition rows are returned with their SQL field names. */
export interface LevelUpSpellAcquisitionBase {
  id: number;
  character_id: number;
  spell_id: number;
  policy_definition_id: number;
  class_level_id: number;
  replaces_id: number | null;
  decided_by_id: number;
  provenance: 'level-history' | 'manual-reconciliation' | 'spellbook-copy';
  quota_bucket: 'cantrips' | 'known' | 'spellbook' | 'cast';
  known: boolean;
  in_spellbook: boolean;
  historical_validation: boolean;
  decision_note: string;
  decided_at: string;
  revision_id: number | null;
  retirement_id: null;
  spell_level: number;
}

export type LevelUpSpellAcquisition =
  | (LevelUpSpellAcquisitionBase & {
      spell_name: string;
      override_rules: boolean;
      copy_details: LevelUpSpellCopyDetails | null;
    })
  | (LevelUpSpellAcquisitionBase & {
      rule_id: number | null;
      opportunity_level_id: number | null;
      origin_known: boolean | null;
      // Unlike simple policies, AdvancedSpellChoices leaves the DBAL JSON string undecoded.
      copy_details: string | null;
    });

export interface LevelUpSpellPolicyPreview {
  policyId: number;
  version: number;
  classId: number;
  className: string;
  sourceName: string;
  classLevel: number;
  classLevelId: null;
  position: number;
  learningStyle: 'known' | 'prepared' | 'spellbook';
  choices: LevelUpSpellOpportunity[];
  limits: {
    maxSpellLevel: number;
    cantrips: number;
    known: number;
    bookAdditions: number;
    replacements: number;
    preparationBase: number | null;
  };
  used: { known: number; cantrips: number; spellbook: number; preparation: number };
  remaining: { known: number; cantrips: number; spellbook: number };
  blocked: string[] | Array<{
    key: string;
    bucket: 'known' | 'cantrips' | 'preparation' | 'source-policy';
    reason: 'quota-impact-unspecified' | 'quota-not-defined-for-mode';
  }>;
  acquisitions: LevelUpSpellAcquisition[];
  automatic: Array<{
    key: string;
    spellId: number;
    acquisitionClassId: number;
    quotaClassId: number;
    castingClassId: number | null;
    bucket: 'known' | 'cantrips' | 'preparation' | null;
    impact: 'free' | 'counts-toward-quota' | 'unspecified';
  }>;
  preparation: { base: number | null; automaticCounted: number; dailyChoicesManaged: false };
}

export interface LevelUpPayload {
  spellChoices?: LevelUpSpellChoice[];
  classId: number;
  subclassId: number | null;
  advancement: LevelAdvancementPayload | null;
  hitPoints: LevelUpHitPointPayload;
  training?: TrainingChoicesPayload;
}

export interface LevelUpResponse {
  message: string;
  level: CharacterClassLevel;
  character: CharacterProfile;
}

export interface HitPointHistoryEntryPayload {
  position: number;
  method: HitPointGainMethod;
  gain?: number;
}

export interface UpdateHitPointHistoryPayload {
  levels: HitPointHistoryEntryPayload[];
}

export interface UpdateHitPointHistoryResponse {
  message: string;
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

interface CharacterProgressionListApiResponse {
  progressions: CharacterProgressionSummary[];
}

interface CharacterProgressionApiResponse {
  progression: CharacterProgressionSummary;
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

  getProgressions(
    campaignId: number,
    characterId: number,
  ): Observable<CharacterProgressionSummary[]> {
    return this.http
      .get<CharacterProgressionListApiResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/characters/${characterId}/progressions`,
      )
      .pipe(map(response => response.progressions));
  }

  addProgression(
    campaignId: number,
    characterId: number,
    progressionId: number,
  ): Observable<CharacterProgressionSummary> {
    return this.http
      .post<CharacterProgressionApiResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/characters/${characterId}/progressions/${progressionId}`,
        {},
      )
      .pipe(map(response => response.progression));
  }

  removeProgression(
    campaignId: number,
    characterId: number,
    progressionId: number,
  ): Observable<void> {
    return this.http.delete<void>(
      `${this.apiUrl}/campaigns/${campaignId}/characters/${characterId}/progressions/${progressionId}`,
    );
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

  getTrainingOptions(characterId: number, classId?: number, subclassId: number | null = null): Observable<TrainingOptions> {
    const params: Record<string, string> = classId === undefined ? {} : { classId: String(classId) };
    if (subclassId !== null) params['subclassId'] = String(subclassId);
    return this.http.get<TrainingOptions>(`${this.apiUrl}/characters/${characterId}/training/options`, { params });
  }

  updateTrainingChoices(characterId: number, payload: TrainingChoicesPayload): Observable<TrainingOptions> {
    return this.http.patch<TrainingOptions>(`${this.apiUrl}/characters/${characterId}/training/choices`, payload);
  }

  getLevelUpOptions(
    campaignId: number,
    characterId: number,
    classId?: number,
    subclassId?: number | null,
  ): Observable<LevelUpOptions> {
    const params: Record<string, string> = {};
    if (classId !== undefined) params['classId'] = String(classId);
    if (subclassId != null) params['subclassId'] = String(subclassId);
    return this.http.get<LevelUpOptions>(
      `${this.apiUrl}/campaigns/${campaignId}/characters/${characterId}/level-up/options`,
      { params },
    );
  }

  qualifySpell(characterId: number, policyId: number, acquisitionId: number, payload: { ruleId: number; reason: string }): Observable<SpellQualificationResponse> {
    return this.http.post<SpellQualificationResponse>(
      `${this.apiUrl}/characters/${characterId}/spell-acquisitions/${policyId}/${acquisitionId}/qualification`, payload,
    );
  }

  getSpellInitialization(characterId: number): Observable<SpellInitializationPreview> {
    return this.http.get<SpellInitializationPreview>(`${this.apiUrl}/characters/${characterId}/spell-acquisitions/initialization`);
  }

  initializeSpells(characterId: number, payload: SpellInitializationPayload): Observable<SpellInitializationResult> {
    return this.http.post<SpellInitializationResult>(`${this.apiUrl}/characters/${characterId}/spell-acquisitions/initialization`, payload);
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

  updateHitPointHistory(
    campaignId: number,
    characterId: number,
    payload: UpdateHitPointHistoryPayload,
  ): Observable<UpdateHitPointHistoryResponse> {
    return this.http.put<UpdateHitPointHistoryResponse>(
      `${this.apiUrl}/campaigns/${campaignId}/characters/${characterId}/hit-points/history`,
      payload,
    );
  }
}

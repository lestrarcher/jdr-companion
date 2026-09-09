import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

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

export interface RaceReference {
  id: number;
  slug: string;
  name: string;
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
}

export interface DndReferenceResponse {
  abilities: AbilityReference[];
  races: RaceReference[];
  classes: ClassReference[];
  subclasses: SubclassReference[];
  feats: FeatReference[];
}

export interface ClassListResponse {
  classes: ClassReference[];
  hitDice: number[];
  spellcastingProgressions: SpellcastingProgressionChoice[];
}

export interface SaveClassPayload {
  slug: string;
  name: string;
  hitDie: number;
  subclassSelectionLevel: number;
  spellcastingProgression: SpellcastingProgression;
  description?: string | null;
  custom?: boolean;
}

export type UpdateClassPayload = Partial<SaveClassPayload>;

export interface RaceListResponse {
  races: RaceReference[];
  abilities: AbilityReference[];
}

export interface SaveRaceAbilityModifierPayload {
  ability: AbilityKey | null;
  value: number;
  choiceKey?: string | null;
}

export interface SaveRacePayload {
  slug: string;
  name: string;
  description?: string | null;
  parentRaceId: number | null;
  featChoiceCount: number;
  custom?: boolean;
  abilityModifiers: SaveRaceAbilityModifierPayload[];
}

export type UpdateRacePayload = Partial<
  Omit<SaveRacePayload, 'abilityModifiers'>
>;

export interface SubclassListResponse {
  subclasses: SubclassReference[];
  spellcastingProgressions: SpellcastingProgressionChoice[];
}

export interface SaveSubclassPayload {
  classId: number;
  slug: string;
  name: string;
  description?: string | null;
  spellcastingProgression?: SpellcastingProgression | null;
  custom?: boolean;
}

export interface FeatListResponse {
  feats: FeatReference[];
  abilities: AbilityReference[];
}

export interface SaveFeatPayload {
  slug: string;
  name: string;
  description: string | null;
  repeatable: boolean;
  requiresAbilityChoice: boolean;
  chosenAbilityIncrease: number;
  allowedAbilities: AbilityKey[];
  custom: boolean;
}

export interface ProgressionListResponse {
  progressions: ProgressionReference[];
}

export interface SaveProgressionPayload {
  slug: string;
  name: string;
  description: string | null;
  minimumValue: number;
  maximumValue: number | null;
  accentColor: string | null;
  gainLabel: string | null;
  spendLabel: string | null;
  bulkAdjustmentEnabled: boolean;
  custom: boolean;
}

export interface SaveProgressionStagePayload {
  label: string;
  minimumValue: number;
  maximumValue: number | null;
  iconUrl: string | null;
  displayOrder: number;
}

@Injectable({ providedIn: 'root' })
export class DndReferenceApiService {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = '/api/dnd';

  getReference(): Observable<DndReferenceResponse> {
    return this.http.get<DndReferenceResponse>(`${this.apiUrl}/reference`);
  }

  getClasses(): Observable<ClassListResponse> {
    return this.http.get<ClassListResponse>(`${this.apiUrl}/classes`);
  }

  createClass(payload: SaveClassPayload): Observable<ClassReference> {
    return this.http
      .post<{ message: string; class: ClassReference }>(
        `${this.apiUrl}/classes`,
        payload,
      )
      .pipe(map(response => response.class));
  }

  updateClass(
    classId: number,
    payload: UpdateClassPayload,
  ): Observable<ClassReference> {
    return this.http
      .patch<{ message: string; class: ClassReference }>(
        `${this.apiUrl}/classes/${classId}`,
        payload,
      )
      .pipe(map(response => response.class));
  }

  getRaces(): Observable<RaceListResponse> {
    return this.http.get<RaceListResponse>(`${this.apiUrl}/races`);
  }

  createRace(payload: SaveRacePayload): Observable<RaceReference> {
    return this.http
      .post<{ message: string; race: RaceReference }>(
        `${this.apiUrl}/races`,
        payload,
      )
      .pipe(map(response => response.race));
  }

  updateRace(
    raceId: number,
    payload: UpdateRacePayload,
  ): Observable<RaceReference> {
    return this.http
      .patch<{ message: string; race: RaceReference }>(
        `${this.apiUrl}/races/${raceId}`,
        payload,
      )
      .pipe(map(response => response.race));
  }

  addRaceAbilityModifier(
    raceId: number,
    payload: SaveRaceAbilityModifierPayload,
  ): Observable<RaceReference> {
    return this.http
      .post<{ message: string; race: RaceReference }>(
        `${this.apiUrl}/races/${raceId}/ability-modifiers`,
        payload,
      )
      .pipe(map(response => response.race));
  }

  getSubclasses(): Observable<SubclassListResponse> {
    return this.http.get<SubclassListResponse>(
      `${this.apiUrl}/subclasses`,
    );
  }

  createSubclass(
    payload: SaveSubclassPayload,
  ): Observable<{ subclass: SubclassReference }> {
    return this.http.post<{ subclass: SubclassReference }>(
      `${this.apiUrl}/subclasses`,
      payload,
    );
  }

  updateSubclass(
    subclassId: number,
    payload: Partial<SaveSubclassPayload>,
  ): Observable<{ subclass: SubclassReference }> {
    return this.http.patch<{ subclass: SubclassReference }>(
      `${this.apiUrl}/subclasses/${subclassId}`,
      payload,
    );
  }

  getFeats(): Observable<FeatListResponse> {
    return this.http.get<FeatListResponse>(
      `${this.apiUrl}/feats`,
    );
  }

  createFeat(
    payload: SaveFeatPayload,
  ): Observable<{ feat: FeatReference }> {
    return this.http.post<{ feat: FeatReference }>(
      `${this.apiUrl}/feats`,
      payload,
    );
  }

  updateFeat(
    featId: number,
    payload: Partial<SaveFeatPayload>,
  ): Observable<{ feat: FeatReference }> {
    return this.http.patch<{ feat: FeatReference }>(
      `${this.apiUrl}/feats/${featId}`,
      payload,
    );
  }

  deleteFeat(
    featId: number,
  ): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(
      `${this.apiUrl}/feats/${featId}`,
    );
  }

  getProgressions(): Observable<ProgressionListResponse> {
    return this.http.get<ProgressionListResponse>(
      `${this.apiUrl}/progressions`,
    );
  }

  createProgression(
    payload: SaveProgressionPayload,
  ): Observable<ProgressionReference> {
    return this.http
      .post<{
        message: string;
        progression: ProgressionReference;
      }>(
        `${this.apiUrl}/progressions`,
        payload,
      )
      .pipe(map(response => response.progression));
  }

  updateProgression(
    progressionId: number,
    payload: Partial<SaveProgressionPayload>,
  ): Observable<ProgressionReference> {
    return this.http
      .patch<{
        message: string;
        progression: ProgressionReference;
      }>(
        `${this.apiUrl}/progressions/${progressionId}`,
        payload,
      )
      .pipe(map(response => response.progression));
  }

  deleteProgression(
    progressionId: number,
  ): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(
      `${this.apiUrl}/progressions/${progressionId}`,
    );
  }

  createProgressionStage(
    progressionId: number,
    payload: SaveProgressionStagePayload,
  ): Observable<ProgressionReference> {
    return this.http
      .post<{
        message: string;
        progression: ProgressionReference;
      }>(
        `${this.apiUrl}/progressions/${progressionId}/stages`,
        payload,
      )
      .pipe(map(response => response.progression));
  }

  updateProgressionStage(
    progressionId: number,
    stageId: number,
    payload: Partial<SaveProgressionStagePayload>,
  ): Observable<ProgressionReference> {
    return this.http
      .patch<{
        message: string;
        progression: ProgressionReference;
      }>(
        `${this.apiUrl}/progressions/${progressionId}/stages/${stageId}`,
        payload,
      )
      .pipe(map(response => response.progression));
  }

  deleteProgressionStage(
    progressionId: number,
    stageId: number,
  ): Observable<ProgressionReference> {
    return this.http
      .delete<{
        message: string;
        progression: ProgressionReference;
      }>(
        `${this.apiUrl}/progressions/${progressionId}/stages/${stageId}`,
      )
      .pipe(map(response => response.progression));
  }
}

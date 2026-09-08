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
}

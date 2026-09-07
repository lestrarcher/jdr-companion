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

export interface RaceAbilityModifierReference {
  id: number;
  sourceRaceId: number;
  ability: AbilityKey | null;
  value: number;
  choiceKey: string | null;
  requiresChoice: boolean;
}

export interface RaceReference {
  id: number;
  slug: string;
  name: string;
  parentRaceId: number | null;
  featChoiceCount: number;
  abilityModifiers: RaceAbilityModifierReference[];
}

export interface ClassReference {
  id: number;
  slug: string;
  name: string;
  hitDie: number;
  subclassSelectionLevel: number;
  spellcastingProgression: SpellcastingProgression;
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

export interface SpellcastingProgressionChoice {
  value: SpellcastingProgression;
  label: string;
}

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

  getSubclasses(): Observable<SubclassListResponse> {
    return this.http.get<SubclassListResponse>(`${this.apiUrl}/subclasses`);
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

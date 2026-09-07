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
  spellcastingProgression:
    | 'none'
    | 'full'
    | 'half'
    | 'artificer'
    | 'third'
    | 'pact';
}

export interface SubclassReference {
  id: number;
  classId: number;
  slug: string;
  name: string;
  spellcastingProgression: ClassReference['spellcastingProgression'] | null;
}

export interface FeatReference {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  repeatable: boolean;
  requiresAbilityChoice: boolean;
  chosenAbilityIncrease: number;
}

export interface DndReferenceResponse {
  abilities: AbilityReference[];
  races: RaceReference[];
  classes: ClassReference[];
  subclasses: SubclassReference[];
  feats: FeatReference[];
}

@Injectable({ providedIn: 'root' })
export class DndReferenceApiService {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = '/api';

  getReference(): Observable<DndReferenceResponse> {
    return this.http.get<DndReferenceResponse>(
      `${this.apiUrl}/dnd/reference`,
    );
  }
}

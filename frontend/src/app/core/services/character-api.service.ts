import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable, map } from 'rxjs';

export interface CreateCharacterPayload {
  slug: string;
  name: string;
  playerName: string | null;
  type: 'player' | 'npc';
  definition: Record<string, unknown>;
}

export interface CharacterApiResponse {
  id: number;
  campaignId: number;
  slug: string;
  name: string;
  playerName: string | null;
  type: 'player' | 'npc';

  race: {
    id: number;
    name: string;
  } | null;

  totalLevel: number;
  proficiencyBonus: number;
  classLevels: CharacterClassLevelApiResponse[];

  definition: Record<string, unknown>;
}
interface CharacterCreateApiResponse {
  character: CharacterApiResponse;
}

interface CharacterListApiResponse {
  characters: CharacterApiResponse[];
}

export interface CharacterClassLevelApiResponse {
  position: number;
  classId: number;
  className: string;
  subclassId: number | null;
  subclassName: string | null;
}

@Injectable({
  providedIn: 'root',
})
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
      .pipe(
        map((response) => response.character),
      );
  }

  list(campaignId: number): Observable<CharacterApiResponse[]> {
    return this.http
      .get<CharacterListApiResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/characters`,
      )
      .pipe(
        map((response) => response.characters),
      );
  }
}

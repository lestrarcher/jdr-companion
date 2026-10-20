import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { map } from 'rxjs';
import { REFERENCE_EDITOR_SCOPE } from './reference-editor-context';
import { RaceListResponse, RaceReference, SaveRaceAbilityModifierPayload, SaveRacePayload, UpdateRacePayload } from './legacy-dnd-reference-api.service';

/** Typed transport adapter. Race mechanics are serialized and validated by Symfony. */
@Injectable()
export class RaceCatalogueApiService {
  private readonly http = inject(HttpClient);
  readonly scope = inject(REFERENCE_EDITOR_SCOPE);
  private readonly url = `/api/reference/${this.scope}/races/editor`;
  getRaces() { return this.http.get<RaceListResponse>(this.url); }
  createRace(payload: SaveRacePayload) {
    const { custom: _legacyOrigin, ...data } = payload;
    return this.http.post<{race: RaceReference}>(this.url,data).pipe(map(r=>r.race));
  }
  updateRace(id:number,payload:UpdateRacePayload) {
    const { custom: _legacyOrigin, slug: _immutableSlug, parentRaceId: _immutableParent, ...data }=payload;
    return this.http.patch<{race:RaceReference}>(`${this.url}/${id}`,data).pipe(map(r=>r.race));
  }
  addRaceAbilityModifier(id:number,payload:SaveRaceAbilityModifierPayload) {
    return this.http.post<{race:RaceReference}>(`${this.url}/${id}/ability-modifiers`,payload).pipe(map(r=>r.race));
  }
}

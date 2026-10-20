import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { map, throwError, Observable, forkJoin } from 'rxjs';
import type { RaceListResponse, DndReferenceResponse } from './legacy-dnd-reference-api.service';
import { REFERENCE_EDITOR_SCOPE } from './reference-editor-context';
import type { ProgressionListResponse, ProgressionReference, SaveProgressionPayload, SaveProgressionStagePayload, SaveProgressionAdjustmentRulePayload } from './legacy-dnd-reference-api.service';
import type { ClassListResponse, ClassReference, SubclassListResponse, SubclassReference, FeatListResponse, FeatReference, SaveClassPayload, UpdateClassPayload, SaveSubclassPayload, SaveFeatPayload } from './legacy-dnd-reference-api.service';

@Injectable()
export class ReferenceCatalogueEditorApiService {
  getReference(): Observable<DndReferenceResponse> {
    return forkJoin({classes:this.getClasses(),subclasses:this.getSubclasses(),feats:this.getFeats(),races:this.http.get<RaceListResponse>(this.url('races'))}).pipe(map(r=>({classes:r.classes.classes,subclasses:r.subclasses.subclasses,feats:r.feats.feats,races:r.races.races,abilities:r.races.abilities})));
  }
  getProgressions() { return this.http.get<ProgressionListResponse>(this.url('progressions')); }
  createProgression(payload: SaveProgressionPayload) {
    const { custom: _origin, ...data } = payload;
    return this.http.post<{item: ProgressionReference}>(this.url('progressions'),data).pipe(map(r=>r.item));
  }
  updateProgression(id: number, payload: Partial<SaveProgressionPayload>) {
    const { custom: _origin, slug: _slug, ...data } = payload;
    return this.http.patch<{item: ProgressionReference}>(`${this.url('progressions')}/${id}`,data).pipe(map(r=>r.item));
  }
  deleteProgression(_id: number): Observable<void> { return throwError(()=>({error:{message:'Suppression de progression indisponible.'}})); }
  createProgressionStage(id: number, data: SaveProgressionStagePayload) { return this.http.post<{progression: ProgressionReference}>(`${this.url('progressions')}/${id}/stages`,data).pipe(map(r=>r.progression)); }
  updateProgressionStage(id: number, childId: number, data: Partial<SaveProgressionStagePayload>) { return this.http.patch<{progression: ProgressionReference}>(`${this.url('progressions')}/${id}/stages/${childId}`,data).pipe(map(r=>r.progression)); }
  deleteProgressionStage(id: number, childId: number) { return this.http.delete<{progression: ProgressionReference}>(`${this.url('progressions')}/${id}/stages/${childId}`).pipe(map(r=>r.progression)); }
  createProgressionAdjustmentRule(id: number, data: SaveProgressionAdjustmentRulePayload) { return this.http.post<{progression: ProgressionReference}>(`${this.url('progressions')}/${id}/adjustment-rules`,data).pipe(map(r=>r.progression)); }
  updateProgressionAdjustmentRule(id: number, childId: number, data: Partial<SaveProgressionAdjustmentRulePayload>) { return this.http.patch<{progression: ProgressionReference}>(`${this.url('progressions')}/${id}/adjustment-rules/${childId}`,data).pipe(map(r=>r.progression)); }
  deleteProgressionAdjustmentRule(id: number, childId: number) { return this.http.delete<{progression: ProgressionReference}>(`${this.url('progressions')}/${id}/adjustment-rules/${childId}`).pipe(map(r=>r.progression)); }
  private readonly http = inject(HttpClient);
  readonly scope = inject(REFERENCE_EDITOR_SCOPE);
  private url(category: string) { return `/api/reference/${this.scope}/${category}/editor`; }
  getClasses() { return this.http.get<ClassListResponse>(this.url('classes')); }
  getSubclasses() { return this.http.get<SubclassListResponse>(this.url('subclasses')); }
  getFeats() { return this.http.get<FeatListResponse>(this.url('feats')); }
  createClass(payload: SaveClassPayload) {
    const { custom: _origin, ...data } = payload;
    return this.http.post<{item: ClassReference}>(this.url('classes'),data).pipe(map(r=>r.item));
  }
  updateClass(id: number, payload: UpdateClassPayload) {
    const { custom: _origin, slug: _slug, ...data } = payload;
    return this.http.patch<{item: ClassReference}>(`${this.url('classes')}/${id}`,data).pipe(map(r=>r.item));
  }
  createSubclass(payload: SaveSubclassPayload) {
    const { custom: _origin, classId, ...data } = payload;
    return this.http.post<{item: SubclassReference}>(this.url('subclasses'),{...data, characterClassId: classId}).pipe(map(r=>({subclass:r.item})));
  }
  updateSubclass(id: number, payload: SaveSubclassPayload) {
    const { custom: _origin, classId: _parent, slug: _slug, ...data } = payload;
    return this.http.patch<{item: SubclassReference}>(`${this.url('subclasses')}/${id}`,data).pipe(map(r=>({subclass:r.item})));
  }
  createFeat(payload: SaveFeatPayload) {
    const { custom: _origin, ...data } = payload;
    return this.http.post<{item: FeatReference}>(this.url('feats'),data).pipe(map(r=>({feat:r.item})));
  }
  updateFeat(id: number, payload: SaveFeatPayload) {
    const { custom: _origin, slug: _slug, ...data } = payload;
    return this.http.patch<{item: FeatReference}>(`${this.url('feats')}/${id}`,data).pipe(map(r=>({feat:r.item})));
  }
  deleteFeat(_id: number): Observable<{message: string}> {
    return throwError(()=>({ error: { message: 'La suppression des dons n’est pas disponible dans le catalogue actuel.' } }));
  }
}

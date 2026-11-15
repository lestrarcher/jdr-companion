import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CharacterInfusions, InfusionView, ActiveInfusion } from './character-infusions';
import { CharacterFeatures } from '../character-features/character-features';

const url = '/api/public/characters/token/infusions';
const known: InfusionView['known'] = [
  {choiceKey:'defense', featureKey:'artificer-infusion-enhanced-defense', name:'Défense améliorée', mode:'existing-item'},
  {choiceKey:'other', featureKey:'artificer-infusion-resistant-armor', name:'Armure résistante', mode:'existing-item'},
  {choiceKey:'third', featureKey:'artificer-infusion-mind-sharpener', name:'Aiguiseur', mode:'existing-item'},
  {choiceKey:'replica', featureKey:'replica', name:'Objet magique reproduit', mode:'create-object'},
];
const active: ActiveInfusion = {id:1, creatorId:10, itemId:30, itemName:'Bouclier ordinaire', recipientId:20, choiceKey:'defense', createdAt:'2026-11-15 10:00:00'};
const empty: InfusionView = {known, quota:2, active:[], preparation:{id:'real-phase', open:true}};
describe('Infusion preparation C3', () => {
  let http: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({providers:[provideHttpClient(), provideHttpClientTesting()]});
    http = TestBed.inject(HttpTestingController);
    Object.defineProperty(HTMLDialogElement.prototype, 'showModal', {configurable:true, value:function (this: HTMLDialogElement) { this.open = true; }});
    Object.defineProperty(HTMLDialogElement.prototype, 'close', {configurable:true, value:function (this: HTMLDialogElement) { this.open = false; }});
  });
  afterEach(() => { http.verify({ignoreCancelled:true}); TestBed.resetTestingModule(); vi.restoreAllMocks(); });
  function flush(view: InfusionView) {
    http.expectOne(url).flush(view);
    http.expectOne(`${url}/recipients`).flush({recipients:[{id:10,name:'Krixx'}, {id:20,name:'Guts'}]});
  }
  function setup(view = empty) {
    const fixture = TestBed.createComponent(CharacterInfusions);
    fixture.componentRef.setInput('accessToken','token'); fixture.detectChanges(); flush(view); fixture.detectChanges(); return fixture;
  }
  function choose(c: CharacterInfusions, key: string, itemId: number) {
    c.selectChoice(key);
    // Existing choices preselect the recipient and start a lookup.
    if (!c.recipientId()) c.selectRecipient(20);
    http.expectOne(`${url}/recipients/20/items?choiceKey=${key}`).flush({items:[{id:itemId,name:`Objet ${itemId}`,eligible:true,reason:null},{id:99,name:'Incompatible',eligible:false,reason:'Support inconnu'}]});
    c.itemId.set(itemId); c.apply();
  }
  it.each([false,true])('shows only active objects in consultation, including readonly with an open phase (%s)', readonly => {
    const f = setup({...empty,active:[active],preparation:{id:'real-phase',open:readonly}});
    f.componentRef.setInput('readonly',readonly); f.detectChanges();
    const c = f.componentInstance;
    expect(f.nativeElement.textContent).toContain('Guts — Bouclier ordinaire');
    expect(f.nativeElement.textContent).toContain('Défense améliorée');
    expect(f.nativeElement.querySelector('.counter').textContent).toContain('1 / 2');
    expect(f.nativeElement.querySelector('.quickbar, dialog, select, .form-actions')).toBeNull();
    expect(f.nativeElement.querySelectorAll('button')).toHaveLength(1); // Refresh only.
    c.selectChoice('defense'); c.selectRecipient(20); c.apply(); c.remove('defense'); c.confirm();
    expect(c.opened()).toBe(false); expect(c.draft()).toHaveLength(1);
    http.expectNone(() => true);
  });
  it('shows a discreet empty consultation without the known catalogue or preparation actions', () => {
    const f = setup({...empty,preparation:{id:null,open:false}});
    expect(f.nativeElement.textContent).toContain('Aucune imprégnation active.');
    expect(f.nativeElement.textContent).not.toContain('Objet magique reproduit');
    expect(f.nativeElement.querySelector('.quickbar, dialog')).toBeNull();
  });
  it('returns to consultation after confirmation and preserves the application at the next rest', () => {
    const f = setup(); const c = f.componentInstance;
    choose(c,'defense',30); c.confirm();
    const closed = {...empty,active:[active],preparation:{id:'real-phase',open:false}};
    http.expectOne(`${url}/preparation/confirm`).flush(closed); f.detectChanges();
    expect(f.nativeElement.querySelector('.quickbar, dialog, .form-actions')).toBeNull();
    expect(f.nativeElement.textContent).toContain('Guts — Bouclier ordinaire');
    f.componentRef.setInput('refresh',1); f.detectChanges();
    flush({...closed,preparation:{id:'next-rest',open:true}}); f.detectChanges();
    expect(f.nativeElement.querySelector('.quickbar')).not.toBeNull();
    c.confirm(); const request = http.expectOne(`${url}/preparation/confirm`);
    expect(request.request.body).toEqual({phaseId:'next-rest',selections:[{choiceKey:'defense',recipientId:20,itemId:30}]});
    request.flush({...closed,preparation:{id:'next-rest',open:false}});
  });
  it('shows glyph tiles, active indication and opens the existing native dialog pattern', () => {
    const f = setup({...empty,active:[active]});
    expect(f.nativeElement.querySelectorAll('.quick-spell')).toHaveLength(4);
    expect(f.nativeElement.textContent).toContain('Appliquée');
    f.nativeElement.querySelector('.quick-spell').click(); f.detectChanges();
    expect(f.nativeElement.querySelector('dialog').open).toBe(true);
    http.expectOne(`${url}/recipients/20/items?choiceKey=defense`).flush({items:[]});
    expect(f.componentInstance.choiceKey()).toBe('defense');
  });
  it('edits several local selections and submits one atomic request only', () => {
    const f = setup(); const c = f.componentInstance; const changed = vi.fn(); c.changed.subscribe(changed);
    choose(c,'defense',30); choose(c,'other',31); choose(c,'defense',32);
    http.expectNone(r => r.method !== 'GET');
    expect(c.draft().map(d => d.itemId)).toEqual([31,32]);
    c.confirm(); c.confirm();
    const request = http.expectOne(`${url}/preparation/confirm`);
    expect(request.request.body).toEqual({phaseId:'real-phase',selections:[{choiceKey:'other',recipientId:20,itemId:31},{choiceKey:'defense',recipientId:20,itemId:32}]});
    request.flush({...empty,preparation:{id:'real-phase',open:false}});
    expect(changed).toHaveBeenCalledOnce(); expect(c.phaseOpen()).toBe(false); expect(c.success()).toContain('terminée');
  });
  it('preserves unchanged selections and sends explicit empty list when all are removed', () => {
    const f = setup({...empty,active:[active]}); const c = f.componentInstance;
    c.confirm(); const request = http.expectOne(`${url}/preparation/confirm`);
    expect(request.request.body.selections).toEqual([{choiceKey:'defense',recipientId:20,itemId:30}]);
    request.flush({message:'Réessayez'}, {status:409,statusText:'Conflict'});
    c.remove('defense'); c.confirm();
    const cleared = http.expectOne(`${url}/preparation/confirm`); expect(cleared.request.body.selections).toEqual([]); cleared.flush({...empty,preparation:{id:'real-phase',open:false}});
  });
  it('previews retained age, newly added order, FIFO and endings before confirmation', () => {
    const second = {...active,id:2,choiceKey:'other',itemId:31};
    const f = setup({...empty,active:[second,active]}); const c = f.componentInstance;
    choose(c,'third',32); f.detectChanges();
    expect(c.evicted().map(d => d.itemId)).toEqual([30]);
    expect(c.planned().map(d => d.itemId)).toEqual([31,32]);
    expect(c.ending()).toEqual([active]);
    expect(f.nativeElement.textContent).toContain('Éviction FIFO');
    expect(f.nativeElement.textContent).toContain('Bouclier ordinaire');
    http.expectNone(r => r.method !== 'GET');
  });
  it('keeps the draft after rejection and refresh of the same phase', () => {
    const f = setup(); const c = f.componentInstance; choose(c,'defense',30);
    const draft = c.draft(); c.confirm(); http.expectOne(`${url}/preparation/confirm`).flush({message:'Objet indisponible'}, {status:409,statusText:'Conflict'});
    expect(c.error()).toBe('Objet indisponible'); expect(c.draft()).toEqual(draft);
    c.load(); flush(empty); expect(c.draft()).toEqual(draft);
    c.confirm(); http.expectOne(`${url}/preparation/confirm`).error(new ProgressEvent('error'));
    expect(c.draft()).toEqual(draft); expect(c.busy()).toBe(false);
  });
  it('opens only the real backend phase, including a new phase after rest', () => {
    const f = setup({...empty,preparation:{id:null,open:false}}); const c = f.componentInstance;
    c.confirm(); c.remove('defense'); http.expectNone(r => r.method !== 'GET');
    f.componentRef.setInput('refresh',1); f.detectChanges(); flush(empty); f.detectChanges();
    expect(c.editable()).toBe(true); expect(f.nativeElement.textContent).toContain('Après le repos long');
  });
  it('retains all distinct replicas and gives an explicit unavailable message without requests', () => {
    const replicas = Array.from({length:49},(_,i) => ({...known[3],choiceKey:`replica-${i}`,name:`Reproduction ${i}`}));
    const f = setup({...empty,known:replicas}); const c = f.componentInstance;
    expect(f.nativeElement.querySelectorAll('.quick-spell')).toHaveLength(49);
    c.selectChoice('replica-0'); f.detectChanges();
    expect(f.nativeElement.querySelector('dialog').textContent).toContain('pas encore disponible');
    expect(f.nativeElement.querySelector('dialog select')).toBeNull(); c.apply(); http.expectNone(r => true);
  });
  it('handles empty recipients, empty supports, errors and cancellation without mutation', () => {
    const f = setup(); const c = f.componentInstance; c.selectChoice('defense'); c.selectRecipient(20);
    http.expectOne(`${url}/recipients/20/items?choiceKey=defense`).flush({items:[]}); expect(c.canApply()).toBe(false);
    c.selectRecipient(20); http.expectOne(`${url}/recipients/20/items?choiceKey=defense`).error(new ProgressEvent('error'));
    expect(c.itemError()).not.toBe(''); c.cancel(); expect(c.draft()).toEqual([]);
  });
  it('blocks readonly mutations and keeps known capabilities in Features', () => {
    const f = setup({...empty,active:[active]}); f.componentRef.setInput('readonly',true); f.detectChanges();
    const c = f.componentInstance; c.confirm(); c.remove('defense'); expect(c.draft()).toHaveLength(1); http.expectNone(r => r.method !== 'GET');
    const features = TestBed.createComponent(CharacterFeatures);
    features.componentRef.setInput('features',[{slug:'infusions-known',name:'Imprégnations connues',sourceType:'class',sourceName:'Artificier'}]); features.componentRef.setInput('accessToken','token'); features.detectChanges();
    http.expectOne('/api/public/characters/token/spells/ability-choices').flush({choices:[]});
    expect(features.nativeElement.querySelector('app-character-infusions')).toBeNull(); expect(features.nativeElement.textContent).toContain('Imprégnations connues');
  });
});

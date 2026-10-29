import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { routes } from '../../app.routes';
import { PlayerSpellPreparationPage } from './player-spell-preparation';
import type { PlayerSpellView } from '@core/services/player-spell-api.service';

const portal = '/campaigns/2/sessions/3/player/test';
const base = '/api/public/characters/test/spells';
@Component({ template: 'Portail' }) class PortalStub {}
function view(): PlayerSpellView {
  return {revision:7, editable:true, spellPreparationAllowed:true, choices:[], warnings:[],
    preparations:[
      {policyId:10, source:'Magicien', ability:'intelligence', learningStyle:'spellbook', maximum:1, selected:[1], blocked:false,
        available:[{id:2,name:'Sort supérieur',level:2},{id:1,name:'Bouclier',level:1}]},
      {policyId:20, source:'Clerc', ability:'wisdom', learningStyle:'prepared', maximum:2, selected:[], blocked:false,
        available:[{id:3,name:'Soins',level:1}]},
    ],
    spells:[{key:'book:1',policyId:10,spellId:1,name:'Bouclier',level:1,school:'abjuration',source:'Magicien',sourceType:'class',
      castingAbility:'intelligence',spellSaveDc:13,spellAttackBonus:5,known:false,inSpellbook:true,prepared:true,
      details:{description:'Description du bouclier',higherLevels:null,castingTime:'1 réaction',range:'Personnelle',duration:'1 round',
        verbal:true,somatic:true,material:false,materialDescription:null,concentration:false,ritual:false}},
      {key:'always:4',spellId:4,name:'Sort de domaine',level:1,school:'evocation',source:'Domaine',sourceType:'subclass',
        castingAbility:'wisdom',spellSaveDc:12,spellAttackBonus:4,known:false,inSpellbook:false,prepared:true,alwaysPrepared:true}],
  };
}
async function setup(data: PlayerSpellView | null = view()) {
  const route = routes.find(route => route.path?.endsWith('/prepare-spells'))!;
  TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting(),provideRouter([
    route, {path:'campaigns/:campaignId/sessions/:sessionId/player/:accessToken',component:PortalStub},
  ])]});
  const harness = await RouterTestingHarness.create();
  const component = await harness.navigateByUrl(portal+'/prepare-spells', PlayerSpellPreparationPage);
  const http = TestBed.inject(HttpTestingController);
  const request = http.expectOne(base);
  if (data) request.flush(data);
  else request.flush({message:'Chargement indisponible'}, {status:500,statusText:'Error'});
  harness.detectChanges();
  return {harness,component,http,element:harness.routeNativeElement!};
}
const originalScroll = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'scrollIntoView');
beforeEach(() => Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', { configurable: true, value: vi.fn() }));
afterEach(() => {
  TestBed.inject(HttpTestingController).verify(); vi.restoreAllMocks(); TestBed.resetTestingModule();
  if (originalScroll) Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', originalScroll);
  else Reflect.deleteProperty(HTMLElement.prototype, 'scrollIntoView');
});

describe('Player spell preparation', () => {
  it('explains an adventure excess without demanding immediate changes', async () => {
    const data = view(); data.spellPreparationAllowed = false;
    data.preparations[0].selected = [1,2];
    const {element, component} = await setup(data);
    expect(component.selected(10)).toEqual([1,2]);
    expect(element.textContent).toContain('Vos sorts restent utilisables');
    expect(element.textContent).toContain('au prochain repos long');
    expect(element.querySelector('.confirmation-bar')).toBeNull();
  });
  it('keeps the excessive selection after rest until the player chooses a valid draft', async () => {
    const data = view(); data.preparations[0].selected = [1,2];
    const {element, component, harness} = await setup(data);
    expect(component.canConfirm()).toBe(false);
    expect(element.textContent).toContain('Choisissez les sorts à conserver');
    component.toggle(component.plans()[0], 2); harness.detectChanges();
    expect(component.canConfirm()).toBe(true);
    expect(component.view()!.preparations[0].selected).toEqual([1,2]);
    expect(element.querySelector('.always-prepared')!.textContent).toContain('Sort de domaine');
  });
  it('opens and closes long details at the end of a 50-spell book without losing either class draft', async () => {
    const data = view();
    data.preparations[0].available = Array.from({length:50}, (_, index) => ({id:index+1, name:`Sort ${index+1}`, level:Math.floor(index/6)+1}));
    data.spells.push({...data.spells[0], key:'book:50', spellId:50, name:'Sort 50', level:9,
      details:{...data.spells[0].details!, description:'Long paragraphe.\n\n'.repeat(100)+'Dernier paragraphe.'}});
    const {component,element,harness,http} = await setup(data);
    component.toggle(component.plans()[1],3); harness.detectChanges();
    expect(element.querySelectorAll('.policy input')).toHaveLength(51);
    const trigger = element.querySelector<HTMLButtonElement>('[aria-label="Détails : Sort 50"]')!;
    trigger.click(); harness.detectChanges(); await harness.fixture.whenStable();
    expect(document.activeElement).toBe(element.querySelector('.preparation-details'));
    expect(element.querySelector('.spell-description')!.textContent).toContain('Dernier paragraphe.');
    expect(HTMLElement.prototype.scrollIntoView).toHaveBeenCalledWith({block:'start'});
    const returns = element.querySelectorAll<HTMLButtonElement>('.return-to-list');
    returns[returns.length-1].click(); harness.detectChanges(); await harness.fixture.whenStable();
    expect(element.querySelector('.preparation-details')).toBeNull();
    expect(document.activeElement).toBe(trigger);
    expect(HTMLElement.prototype.scrollIntoView).toHaveBeenCalledWith({block:'center'});
    expect(component.selected(10)).toEqual([1]); expect(component.selected(20)).toEqual([3]);
    http.expectNone(request => request.method !== 'GET');
  });
  it('keeps details separate from selection and summarizes independent quotas', async () => {
    const {component,element,harness,http} = await setup();
    const detail = element.querySelector<HTMLButtonElement>('[aria-label="Détails : Bouclier"]')!;
    expect(detail.closest('label')).toBeNull();
    detail.click(); harness.detectChanges();
    expect(component.selected(10)).toEqual([1]);
    element.querySelector<HTMLLabelElement>('.spell-choice label')!.click(); harness.detectChanges();
    expect(component.selected(10)).toEqual([]);
    expect(element.querySelector('.confirmation-summary')!.textContent).toContain('0 sélectionné(s)');
    expect(element.querySelector('.confirmation-summary')!.textContent).toContain('2 classes');
    component.toggle(component.plans()[0],1); component.toggle(component.plans()[0],2); harness.detectChanges();
    expect(element.querySelector('.confirmation-summary')!.textContent).toContain('1 sort(s) en trop');
    expect(element.querySelector<HTMLButtonElement>('.confirm')!.disabled).toBe(true);
    http.expectNone(request => request.method !== 'GET');
  });
  it('renders locked selections as accessible indicators without a confirmation bar', async () => {
    const {element} = await setup({...view(),spellPreparationAllowed:false});
    expect(element.querySelector('input[type="checkbox"]')).toBeNull();
    expect(element.querySelector('.confirmation-bar')).toBeNull();
    expect(element.querySelector('.readonly-selection')!.textContent).toContain('Préparé');
    expect(element.querySelector('.locked')!.textContent).toContain('verrouillée');
    expect(element.querySelector('[aria-label="Détails : Bouclier"]')).not.toBeNull();
  });
  it('prefills each class, groups levels and distinguishes immutable always-prepared spells', async () => {
    const {component,element,http} = await setup();
    expect(component.selected(10)).toEqual([1]); expect(component.selected(20)).toEqual([]);
    expect(Array.from(element.querySelectorAll('.policy h3')).map(node => node.textContent)).toEqual(['Niveau 1','Niveau 2','Niveau 1']);
    const inputs = element.querySelectorAll<HTMLInputElement>('input');
    expect([...inputs].map(input => input.checked)).toEqual([true,false,false]);
    expect(element.querySelector('.counter')!.textContent).toContain('1 / 1');
    expect(element.querySelector('.always-prepared')!.textContent).toContain('Sort de domaine');
    expect(element.querySelector('.always-prepared input')).toBeNull();
    expect(element.querySelector('.back-link')!.getAttribute('href')).toBe(portal+'?tab=spells');
    http.expectNone(request => request.method === 'POST');
  });
  it('keeps checkbox changes local, permits exceeding the quota but blocks confirmation', async () => {
    const {component,element,harness,http} = await setup();
    element.querySelectorAll<HTMLInputElement>('input')[1].click(); harness.detectChanges();
    expect(component.selected(10)).toEqual([1,2]); expect(component.dirty()).toBe(true);
    expect(component.view()!.preparations[0].selected).toEqual([1]);
    expect(element.textContent).toContain('Quota dépassé');
    expect(element.querySelector<HTMLButtonElement>('.confirm')!.disabled).toBe(true);
    component.confirm(); http.expectNone(request => request.method === 'POST');
    element.querySelectorAll<HTMLInputElement>('input')[1].click(); harness.detectChanges();
    expect(component.dirty()).toBe(false); expect(component.canConfirm()).toBe(true);
  });
  it('sends all policies once, accepts empty selections and applies the locked response', async () => {
    const {component,http,harness,element} = await setup();
    component.toggle(component.plans()[0],1); component.toggle(component.plans()[1],3);
    component.confirm(); component.confirm();
    const request = http.expectOne(base+'/confirm-preparation');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({revision:7,selections:[{policyId:10,spellIds:[]},{policyId:20,spellIds:[3]}]});
    expect(component.canLeavePreparation()).toBe(false);
    const response = view(); response.revision=8; response.spellPreparationAllowed=false;
    response.preparations[0].selected=[]; response.preparations[1].selected=[3];
    request.flush(response); await harness.fixture.whenStable();
    expect(component.view()!.revision).toBe(8); expect(component.dirty()).toBe(false);
    expect(component.view()!.spellPreparationAllowed).toBe(false);
    expect(TestBed.inject(Router).url).toBe(portal+'?tab=spells');
    http.expectNone(request => request.method === 'PATCH');
    component.toggle(component.plans()[0],1); component.confirm();
    expect(component.selected(10)).toEqual([]); http.expectNone(request => request.method === 'POST');
  });
  it('allows confirmation without changing the prior selection', async () => {
    const {component,http,harness} = await setup();
    expect(component.canConfirm()).toBe(true); component.confirm();
    const request = http.expectOne(base+'/confirm-preparation');
    expect(request.request.body.selections).toEqual([{policyId:10,spellIds:[1]},{policyId:20,spellIds:[]}]);
    request.flush({...view(),revision:8,spellPreparationAllowed:false});
    await harness.fixture.whenStable();
  });
  it.each(['locked','closed','blocked'] as const)('prevents editing/confirmation when %s', async mode => {
    const data = view();
    if (mode === 'locked') data.spellPreparationAllowed=false;
    if (mode === 'closed') data.editable=false;
    if (mode === 'blocked') data.preparations[0].blocked=true;
    const {component,http} = await setup(data);
    component.toggle(component.plans()[0],1); component.confirm();
    expect(component.selected(10)).toEqual([1]); expect(component.canConfirm()).toBe(false);
    http.expectNone(request => request.method === 'POST');
  });
  it('retains a conflicted draft until the player explicitly agrees to replace it', async () => {
    const {component,http,harness,element} = await setup();
    component.toggle(component.plans()[0],1); component.confirm();
    http.expectOne(base+'/confirm-preparation').flush({message:'Révision périmée'}, {status:409,statusText:'Conflict'});
    harness.detectChanges();
    expect(element.textContent).toContain('Votre brouillon est conservé');
    expect(component.selected(10)).toEqual([]); expect(component.view()!.revision).toBe(7);
    expect(TestBed.inject(Router).url).toBe(portal+'/prepare-spells');
    component.confirm(); http.expectNone(request => true);
    const prompt = vi.spyOn(window,'confirm').mockReturnValue(false);
    component.reload(); http.expectNone(base); expect(component.selected(10)).toEqual([]);
    prompt.mockReturnValue(true); component.reload();
    http.expectOne(base).flush({...view(),revision:9,spellPreparationAllowed:false});
    expect(component.selected(10)).toEqual([1]); expect(component.dirty()).toBe(false);
    expect(component.conflict()).toBe(false); expect(component.canConfirm()).toBe(false);
  });
  it('shows backend validation errors without discarding the draft', async () => {
    const {component,http,harness,element} = await setup();
    component.toggle(component.plans()[0],1); component.confirm();
    http.expectOne(base+'/confirm-preparation').flush({detail:'Politique manquante'}, {status:422,statusText:'Invalid'});
    harness.detectChanges(); expect(element.textContent).toContain('Politique manquante');
    expect(component.selected(10)).toEqual([]); expect(component.canConfirm()).toBe(true);
    expect(TestBed.inject(Router).url).toBe(portal+'/prepare-spells');
    http.expectNone(base);
  });
  it('reuses spell details without requests or loss of the local draft', async () => {
    const {component,harness,element,http} = await setup();
    component.toggle(component.plans()[0],1);
    element.querySelector<HTMLButtonElement>('[aria-label="Détails : Bouclier"]')!.click(); harness.detectChanges();
    expect(element.querySelector('.spell-details')!.textContent).toContain('Description du bouclier');
    expect(element.querySelector('.quickbar')).toBeNull();
    expect(component.selected(10)).toEqual([]); http.expectNone(request => true);
  });
  it('guards route departure and browser reload only for unsaved changes', async () => {
    const {component,harness,element} = await setup();
    const prompt = vi.spyOn(window,'confirm').mockReturnValue(false);
    expect(component.canLeavePreparation()).toBe(true); expect(prompt).not.toHaveBeenCalled();
    component.toggle(component.plans()[0],1);
    const event = new Event('beforeunload',{cancelable:true}); window.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(true);
    await harness.navigateByUrl(portal+'?tab=spells');
    expect(TestBed.inject(Router).url).toBe(portal+'/prepare-spells');
    expect(component.selected(10)).toEqual([]);
    prompt.mockReturnValue(true);
    await harness.navigateByUrl(element.querySelector('.back-link')!.getAttribute('href')!, PortalStub);
    expect(TestBed.inject(Router).url).toBe(portal+'?tab=spells');
  });
  it('recovers from loading errors and handles a character without preparation policies', async () => {
    const {component,http,harness,element} = await setup(null);
    expect(element.textContent).toContain('Chargement indisponible'); expect(component.canConfirm()).toBe(false);
    component.reload(); http.expectOne(base).flush({...view(),preparations:[],spells:[]}); harness.detectChanges();
    expect(element.textContent).toContain('Aucune préparation'); expect(component.canConfirm()).toBe(false);
  });
});

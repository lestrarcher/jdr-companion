import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { ActivatedRoute, convertToParamMap, provideRouter, Router } from '@angular/router';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { of, Subject } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CharacterApiService } from '@core/services/character-api.service';
import { CharacterSessionStateApiService } from '@core/services/character-session-state-api.service';
import { CharacterLevelUp } from '../campaign-characters/components/character-level-up/character-level-up';
import { CharacterLevelUpPage } from './character-level-up-page';
import { PlayerLevelUp } from '../player-level-up/player-level-up';

const spell = (id: number) => ({ id, name: `Sort ${id}`, school: 'abjuration', level: 1 });
const opportunity = (ruleId: number | null = 10, remaining = 1): any => ({
  policyId: 1, classId: 2, classLevel: 7, ruleId, key: `rule-${ruleId}`, quota: 'known', remaining,
  available: [spell(20), spell(21)], replacementAvailable: [spell(22)],
  replacement: { maximum: 1, budgetKey: 'shared', confirmationRequired: true,
    acquisitions: [{ acquisitionId: 30, spellId: 31, requiresQualification: false }] },
});
const preview = (choices = [opportunity()]): any => ({ status: 'choices-to-review', policies: [{
  policyId: 1, classId: 2, className: 'Classe', classLevel: 7, choices, blocked: [],
  learningStyle: 'known', acquisitions: [], remaining: { known: 2, cantrips: 0, spellbook: 0 },
  used: { known: 4 }, limits: { known: 6 },
}] });
const options: any = { canLevelUp: true, currentTotalLevel: 6, nextTotalLevel: 7, abilities: [], feats: [],
  hitPointMethods: [{ value: 'average', label: 'Moyenne' }],
  classes: [{ id: 2, name: 'Classe', currentLevel: 6, nextLevel: 7, hitDie: 10, averageHitPointGain: 6,
    eligible: true, currentSubclass: null, subclassRequired: false, advancementRequired: false, subclasses: [] }] };
const profile: any = { id: 7, name: 'Test', totalLevel: 6, hitPoints: {maximumValue: 40}, abilities: [], classLevels: [], training: { proficiencies: [], languages: [], expertises: [] } };
const training: any = { choices: [], proficiencyRules: [], expertiseRules: [], definitions: { proficiencies: [], languages: [] } };

describe('Spell qualification API', () => {
  it('posts only the confirmed rule and reason to the character acquisition endpoint', () => {
    TestBed.configureTestingModule({providers: [provideHttpClient(), provideHttpClientTesting()]});
    const api = TestBed.inject(CharacterApiService); const http = TestBed.inject(HttpTestingController);
    const received = vi.fn();
    api.qualifySpell(7, 1, 30, {ruleId: 10, reason: 'Confirmation MJ'}).subscribe(received);
    const request = http.expectOne('/api/characters/7/spell-acquisitions/1/30/qualification');
    expect(request.request.method).toBe('POST'); expect(request.request.body).toEqual({ruleId:10,reason:'Confirmation MJ'});
    request.flush({qualification:{acquisitionId:30,ruleId:10,originKnown:true}});
    expect(received).toHaveBeenCalledOnce(); http.verify();
  });
});

describe('Level-up spell confirmation', () => {
  beforeEach(() => TestBed.configureTestingModule({ providers: [provideRouter([])] }));
  function form(data = preview(), enabled = true) {
    const f = TestBed.createComponent(CharacterLevelUp);
    f.componentRef.setInput('character', profile); f.componentRef.setInput('options', options);
    f.componentRef.setInput('abilities', []); f.componentRef.setInput('feats', []);
    if (enabled) f.componentRef.setInput('spellPreview', () => of(data));
    f.detectChanges();
    const c: any = f.componentInstance;
    const emitted = vi.fn(); c.submitted.subscribe(emitted);
    return { f, c, emitted };
  }
  it('blocks missing learning and emits complete selections with a summary', () => {
    const { f, c, emitted } = form(); c.submit(); expect(emitted).not.toHaveBeenCalled();
    c.toggleSpell(c.spellOpportunities()[0], 20); f.detectChanges(); c.submit();
    expect(emitted.mock.calls[0][0].spellChoices).toEqual([{ policyId: 1, spellId: 20, ruleId: 10 }]);
    expect(f.nativeElement.querySelector('[aria-label="Récapitulatif des sorts sélectionnés"]').textContent).toContain('Sort 20');
  });
  it('sends empty explicit choices when nothing is required, but omits the field in legacy mode', () => {
    const explicit = form(preview([opportunity(null, 0)])); explicit.c.submit();
    expect(explicit.emitted.mock.calls[0][0].spellChoices).toEqual([]);
    const legacy = form(preview(), false); legacy.c.submit();
    expect(legacy.emitted.mock.calls[0][0]).not.toHaveProperty('spellChoices');
  });
  it('accepts authoritative no-spellcasting and sends an explicit empty list', () => {
    const { c, emitted } = form({ status: 'no-spellcasting', policies: [], classId: 2, classLevel: 7 });
    c.submit(); expect(emitted.mock.calls[0][0].spellChoices).toEqual([]);
  });
  it.each(['policy-to-configure','policy-ambiguous'])('blocks %s instead of submitting an empty bypass', status => {
    const { c, emitted } = form({ status, policies: [], classId: 2, classLevel: 7 });
    c.submit(); expect(emitted).not.toHaveBeenCalled(); expect(c.spellChoicesComplete()).toBe(false);
  });
  it('blocks a preview HTTP error and clears old selections while a new class preview loads', () => {
    const { f, c, emitted } = form(); c.toggleSpell(c.spellOpportunities()[0],20);
    const pending=new Subject<any>();
    const loader=vi.fn(() => pending);
    f.componentRef.setInput('spellPreview',loader);
    f.componentRef.setInput('options',{...options,classes:[...options.classes,{...options.classes[0],id:3,currentLevel:0,nextLevel:1}]});
    c.form.classId=3; c.classChanged();
    expect(loader).toHaveBeenCalledWith(3,null); expect(c.spells()).toBeNull(); expect(c.spellSelection()).toEqual({});
    c.submit(); expect(emitted).not.toHaveBeenCalled();
    pending.error(new HttpErrorResponse({status:500,error:{message:'Aperçu indisponible'}})); f.detectChanges();
    expect(f.nativeElement.textContent).toContain('Aperçu indisponible'); c.submit(); expect(emitted).not.toHaveBeenCalled();
  });
  it('preserves two rule IDs and does not sum their shared replacement budget', () => {
    const { c, emitted } = form(preview([opportunity(10), opportunity(11)]));
    c.toggleSpell(c.spellOpportunities()[0], 20); c.toggleSpell(c.spellOpportunities()[1], 21); c.submit();
    expect(emitted.mock.calls[0][0].spellChoices.map((x: any) => x.ruleId)).toEqual([10, 11]);
    expect(c.replacementBudgets()).toBe(1);
    c.spellSelection.set(Object.fromEntries(c.spellOpportunities().map((r: any) => [c.spellKey(r), [20]])));
    expect(c.spellChoicesComplete()).toBe(false);
  });
  it('caps learning by the server quota without consuming a replacement budget', () => {
    const data = preview([opportunity(10), opportunity(11)]); data.policies[0].remaining.known = 1;
    const { c } = form(data); c.toggleSpell(c.spellOpportunities()[0], 20);
    expect(c.spellChoicesComplete()).toBe(true);
    c.toggleSpell(c.spellOpportunities()[1], 21); expect(c.spellChoicesComplete()).toBe(false);
  });
  it('requires a complete replacement, preserves acquisition identity, and allows disabling it', () => {
    const { c, emitted } = form(); c.toggleSpell(c.spellOpportunities()[0], 20);
    c.toggleReplacement(true); expect(c.spellChoicesComplete()).toBe(false);
    c.selectForgotten(30); c.selectReplacementSpell(22); c.submit();
    expect(emitted.mock.calls[0][0].spellChoices[1]).toEqual({ policyId: 1, spellId: 22, ruleId: 10, replacesAcquisitionId: 30, replacementConfirmed: true });
    c.replacementSpellId.set(20); expect(c.spellChoicesComplete()).toBe(false);
    c.toggleReplacement(false); c.submit(); expect(emitted.mock.calls[1][0].spellChoices).toHaveLength(1);
  });
  it('blocks qualification and permits confirming learning after disabling replacement', () => {
    const data = preview(); data.policies[0].choices[0].replacement.acquisitions[0].requiresQualification = true;
    const { f, c, emitted } = form(data); c.toggleSpell(c.spellOpportunities()[0], 20);
    c.toggleReplacement(true); c.selectForgotten(30); c.selectReplacementSpell(22); f.detectChanges(); c.submit();
    expect(emitted).not.toHaveBeenCalled(); expect(f.nativeElement.textContent).toContain('ne peut pas encore être enregistré');
    c.toggleReplacement(false); c.submit(); expect(emitted).toHaveBeenCalledOnce();
  });
  it('clears selections on class change and rejects loading, errors, missing policies and quota overflow', () => {
    const { c } = form(); c.toggleSpell(c.spellOpportunities()[0], 20); c.classChanged();
    expect(c.spellSelection()).toEqual({}); expect(c.replacementEnabled()).toBe(false);
    c.toggleSpell(c.spellOpportunities()[0], 20);
    c.spellsLoading.set(true); expect(c.spellChoicesComplete()).toBe(false); c.spellsLoading.set(false);
    c.spellsError.set('Erreur'); expect(c.spellChoicesComplete()).toBe(false); c.spellsError.set(null);
    c.spellSelection.set({ [c.spellKey(c.spellOpportunities()[0])]: [20, 21] }); expect(c.spellChoicesComplete()).toBe(false);
    c.spells.set({status: 'policy-to-configure', policies: [], classId: 2, classLevel: 7}); expect(c.spellChoicesComplete()).toBe(false);
  });
  it('qualifies explicitly for the GM then reloads authoritative preview without submitting', () => {
    const data = preview(); data.policies[0].choices[0].replacement.acquisitions[0].requiresQualification = true;
    data.policies[0].choices[0].replacement.acquisitions[0].spellName = 'Sort historique';
    const { f, c, emitted } = form(data);
    const pending = new Subject<any>(); const refreshed = new Subject<any>();
    const qualify = vi.fn(() => pending); const reload = vi.fn(() => refreshed);
    f.componentRef.setInput('qualifySpell', qualify); f.componentRef.setInput('spellPreview', reload);
    c.toggleSpell(c.spellOpportunities()[0], 20); c.toggleReplacement(true); c.selectForgotten(30); c.selectReplacementSpell(22);
    f.detectChanges();
    expect(f.nativeElement.textContent).toContain('Qualifier ce sort');
    expect(f.nativeElement.textContent).toContain('Sort historique');
    expect(f.nativeElement.textContent).toContain('rule-10');
    expect(qualify).not.toHaveBeenCalled(); c.confirmQualification(); expect(qualify).not.toHaveBeenCalled();
    c.qualificationReason.set('  Voie confirmée par le MJ  '); c.confirmQualification(); c.confirmQualification();
    expect(qualify).toHaveBeenCalledExactlyOnceWith(1, 30, 10, 'Voie confirmée par le MJ');
    expect(c.spellChoicesComplete()).toBe(false);
    pending.next({ qualification: {originKnown: true} });
    expect(reload).toHaveBeenCalledExactlyOnceWith(2, null); expect(c.spellChoicesComplete()).toBe(false);
    expect(emitted).not.toHaveBeenCalled();
    const updated = structuredClone(data); updated.policies[0].choices[0].replacement.acquisitions[0].requiresQualification = false;
    refreshed.next(updated); f.detectChanges();
    expect(c.selectedSpells(c.spellOpportunities()[0])).toEqual([20]);
    expect(c.replacementSpellId()).toBe(22); expect(c.spellChoicesComplete()).toBe(true);
    expect(f.nativeElement.textContent).toContain('Qualification enregistrée'); expect(emitted).not.toHaveBeenCalled();
    expect(f.nativeElement.textContent).not.toContain('Qualifier ce sort');
  });
  it('preserves justification and selections on qualification error, and has no player action', () => {
    const data = preview(); data.policies[0].choices[0].replacement.acquisitions[0].requiresQualification = true;
    const { f, c, emitted } = form(data);
    c.toggleSpell(c.spellOpportunities()[0], 20); c.toggleReplacement(true); c.selectForgotten(30); f.detectChanges();
    expect(f.nativeElement.textContent).not.toContain('Qualifier ce sort');
    const pending = new Subject<any>(); f.componentRef.setInput('qualifySpell', () => pending);
    c.qualificationOpen.set(true); c.qualificationReason.set('Justification conservée'); c.confirmQualification();
    pending.error(new HttpErrorResponse({status:409,error:{message:'Règle différente'}})); f.detectChanges();
    expect(f.nativeElement.textContent).toContain('Règle différente'); expect(c.qualificationReason()).toBe('Justification conservée');
    expect(c.selectedSpells(c.spellOpportunities()[0])).toEqual([20]); expect(c.spellChoicesComplete()).toBe(false);
    c.toggleReplacement(false); c.submit(); expect(emitted).toHaveBeenCalledOnce();
  });
  it('blocks a failed refresh and ignores a qualification response after changing class', () => {
    const data = preview(); data.policies[0].choices[0].replacement.acquisitions[0].requiresQualification = true;
    const { f, c } = form(data); const pending = new Subject<any>(); const refreshed = new Subject<any>();
    f.componentRef.setInput('qualifySpell', () => pending); f.componentRef.setInput('spellPreview', () => refreshed);
    c.toggleReplacement(true); c.selectForgotten(30); c.qualificationReason.set('Motif'); c.confirmQualification();
    pending.next({}); refreshed.error(new HttpErrorResponse({status:500,error:{message:'Rechargement impossible'}})); f.detectChanges();
    expect(c.spellChoicesComplete()).toBe(false); expect(f.nativeElement.textContent).toContain('Recharger la prévisualisation');
    f.componentRef.setInput('spellPreview', () => of(data)); c.classChanged();
    c.toggleReplacement(true); c.selectForgotten(30); c.qualificationReason.set('Autre motif'); c.confirmQualification();
    c.classChanged(); pending.next({});
    expect(c.qualificationSuccess()).toBeNull(); expect(c.spellSelection()).toEqual({}); expect(c.qualificationLoading()).toBe(false);
  });
});

describe.each([['MJ', CharacterLevelUpPage], ['joueur', PlayerLevelUp]] as const)('Submission %s', (_, component) => {
  it('forwards the shared payload once, retains selections on HTTP error, then navigates on success', () => {
    let pending = new Subject<any>();
    const api = { getProfile: vi.fn(() => of(profile)), getByAccessToken: vi.fn(() => of({character: profile})),
      getTrainingOptions: vi.fn(() => of(training)),
      getLevelUpOptions: vi.fn(() => of({...options, training, spellPreview: preview()})),
      qualifySpell: vi.fn(() => of({qualification:{originKnown:true}})),
      levelUp: vi.fn(() => pending) };
    TestBed.configureTestingModule({providers: [provideRouter([]),
      {provide: ActivatedRoute, useValue: {snapshot: {paramMap: convertToParamMap({campaignId:'1',characterId:'7',sessionId:'3',accessToken:'token'})}}},
      {provide: CharacterApiService, useValue: api}, {provide: CharacterSessionStateApiService, useValue: api}]});
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    const f = TestBed.createComponent(component as typeof CharacterLevelUpPage); f.detectChanges();
    const child: any = f.debugElement.query(By.directive(CharacterLevelUp)).componentInstance;
    expect(!!child.qualifySpell()).toBe(component === CharacterLevelUpPage);
    if (component === CharacterLevelUpPage) {
      child.qualifySpell()(1,30,10,'Motif MJ').subscribe();
      expect(api.qualifySpell).toHaveBeenCalledExactlyOnceWith(7,1,30,{ruleId:10,reason:'Motif MJ'});
      expect(api.levelUp).not.toHaveBeenCalled();
    }
    child.toggleSpell(child.spellOpportunities()[0], 20); child.submit(); child.submit();
    expect(api.levelUp).toHaveBeenCalledOnce();
    const args = api.levelUp.mock.calls[0] as unknown as any[];
    expect(args.at(-1).spellChoices).toEqual([{policyId:1,spellId:20,ruleId:10}]);
    pending.error(new HttpErrorResponse({status:409,error:{message:'Choix périmés'}})); f.detectChanges();
    expect(f.nativeElement.textContent).toContain('Choix périmés');
    expect(f.debugElement.query(By.directive(CharacterLevelUp)).componentInstance).toBe(child);
    expect(child.selectedSpellChoices()).toHaveLength(1);
    pending = new Subject<any>(); child.submit(); pending.next({}); pending.complete();
    expect(navigate).toHaveBeenCalledOnce();
  });
});

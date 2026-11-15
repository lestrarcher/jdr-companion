import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { of, Subject, throwError } from 'rxjs';
import { describe, expect, it, vi } from 'vitest';
import { CharacterFeatureChoices } from './character-feature-choices';
import { FeatureChoicesApi, FeatureChoiceView } from '@core/services/feature-choices-api.service';
import { CharacterLevelUp } from '../character-level-up/character-level-up';
import { CharacterBuilder } from '../../../character-builder/character-builder';
import { CharacterBuilderApiService } from '@core/services/character-builder-api.service';
import { DndReferenceApiService } from '@core/services/dnd-reference-api.service';
import { ActivatedRoute, convertToParamMap } from '@angular/router';

function view(): FeatureChoiceView {
  return {fingerprint:'v1',missing:['style-1'],issues:[],history:[],grants:[{
    key:'style-1',name:'Styles',classKey:'fighter',classLevel:1,unlockLevel:1,choice_count:1,
    newAtLevel:true,replacementAvailable:false,remaining:1,selected:[],options:[
      {key:'defense',featureKey:'defense',name:'Défense',description:'Avec armure',prerequisites:{},reasons:[]},
      {key:'other',featureKey:'other',name:'Autre option',description:null,prerequisites:{features:['pact']},reasons:['features:pact']},
    ],
  }]};
}
function editor(data = view(), load = vi.fn(() => of(data)), save?: any) {
  const f = TestBed.createComponent(CharacterFeatureChoices);
  f.componentRef.setInput('load', load); f.componentRef.setInput('classKey', 'fighter');
  if (save) f.componentRef.setInput('save', save);
  const changed = vi.fn(); f.componentInstance.changed.subscribe(changed);
  f.detectChanges();
  return {f,c:f.componentInstance as any,changed,load};
}
describe('Feature choice editor', () => {
  function infusions(): FeatureChoiceView {
    const data = view();
    Object.assign(data.grants[0], {key:'artificer-infusions-2',name:'Imprégnations connues',classKey:'artificer',classLevel:2,unlockLevel:2,choice_count:4,remaining:4,
      options:['Défense améliorée','Arme améliorée','Focaliseur arcanique amélioré','Arme de retour','Tir à répétition','Bottes du chemin sinueux','Arme radiante'].map((name,index)=>({
        key:`infusion-${index}`,featureKey:`infusion-${index}`,name,description:'Imprégnation connue',prerequisites:{classLevels:{artificer:2}},reasons:[],
      }))});
    data.grants[0].options.push({key:'helm',featureKey:'helm',name:'Heaume de vigilance',description:null,prerequisites:{classLevels:{artificer:10}},reasons:['classLevels:artificer']});
    return data;
  }
  it('requires four artificer infusions at level two and shows server level restrictions', () => {
    const data=infusions(); const {f,c,changed}=editor(data);
    f.componentRef.setInput('classKey','artificer'); f.detectChanges();
    expect(f.nativeElement.querySelectorAll('select')).toHaveLength(4);
    expect(f.nativeElement.querySelector('option[value="helm"]').disabled).toBe(true);
    for (let slot=1; slot<=3; slot++) c.select(data.grants[0],slot,`infusion-${slot-1}`);
    expect(c.complete()).toBe(false);
    c.select(data.grants[0],4,'infusion-3'); f.detectChanges();
    expect(c.complete()).toBe(true);
    expect(changed.mock.calls.at(-1)?.[0].selections).toHaveLength(4);
    expect(f.nativeElement.textContent).toContain('Défense améliorée');
  });
  it('keeps level-three replacement optional and combines it with two new choices at level six', () => {
    const data=infusions(); const grant=data.grants[0];
    Object.assign(grant,{newAtLevel:false,classLevel:3,remaining:0,replacementAvailable:true,
      selected:Array.from({length:4},(_,i)=>({key:`known-${i}`,slot:i+1,optionKey:`infusion-${i}`,valid:true}))});
    const {f,c}=editor(data);
    expect(c.complete()).toBe(true); expect(c.selections()).toEqual([]);
    expect(f.nativeElement.textContent).toContain('Défense améliorée');
    c.select(grant,1,'infusion-4');
    expect(c.selections()).toEqual([{grantKey:grant.key,optionKey:'infusion-4',slot:1,replacesKey:'known-0'}]);
    c.select(grant,1,''); expect(c.complete()).toBe(true);
    const next={...grant,key:'artificer-infusions-6',classLevel:6,unlockLevel:6,choice_count:2,remaining:2,newAtLevel:true,selected:[]};
    c.view.set({...data,grants:[grant,next]});
    c.select(grant,1,'infusion-4'); c.select(next,1,'infusion-5');
    expect(c.complete()).toBe(false); c.select(next,2,'infusion-6');
    expect(c.complete()).toBe(true); expect(c.selections()).toHaveLength(3);
    expect(c.selections().filter((row:any)=>row.replacesKey)).toHaveLength(1);
  });
  it('loads the class, requires new slots and emits stable keys', () => {
    const {f,c,changed,load} = editor();
    expect(load).toHaveBeenCalledWith('fighter',null); expect(c.complete()).toBe(false);
    const select = f.nativeElement.querySelector('select'); select.value='defense'; select.dispatchEvent(new Event('change')); f.detectChanges();
    expect(changed.mock.calls.at(-1)?.[0]).toEqual({complete:true,selections:[{grantKey:'style-1',optionKey:'defense',slot:1}]});
    expect(f.nativeElement.textContent).toContain('Avec armure');
  });
  it('shows server prerequisites without preventing same-batch dependencies', () => {
    const {f,c} = editor(); c.select(c.view().grants[0],1,'other'); f.detectChanges();
    expect(f.nativeElement.textContent).toContain('Capacité requise : pact');
    expect(c.selections()[0].optionKey).toBe('other');
  });
  it('keeps historical missing slots read-only on level-up and does not block them', () => {
    const data=view(); data.grants[0].newAtLevel=false;
    const {f,c}=editor(data);
    expect(f.nativeElement.querySelector('select')).toBeNull(); expect(c.complete()).toBe(true);
    c.select(data.grants[0],1,'defense'); expect(c.selections()).toEqual([]);
  });
  it('disables options refused by the backend for the projected class level', () => {
    const data=view(); data.grants[0].options[1].reasons=['classLevels:warlock'];
    const {f,c}=editor(data); expect(f.nativeElement.querySelector('option[value="other"]').disabled).toBe(true);
    c.select(data.grants[0],1,'other'); expect(c.selections()).toEqual([]);
  });
  it('replaces an authorized selection and allows cancelling the replacement', () => {
    const data=view(); Object.assign(data.grants[0],{newAtLevel:false,replacementAvailable:true,selected:[{key:'original',slot:1,optionKey:'defense',valid:true}]});
    const {c}=editor(data); c.select(data.grants[0],1,'other');
    expect(c.selections()).toEqual([{grantKey:'style-1',optionKey:'other',slot:1,replacesKey:'original'}]);
    c.select(data.grants[0],1,''); expect(c.selections()).toEqual([]);
  });
  it('rejects late responses after changing class and clears old selections', () => {
    const first=new Subject<FeatureChoiceView>(), second=new Subject<FeatureChoiceView>();
    const load=vi.fn().mockReturnValueOnce(first).mockReturnValueOnce(second);
    const {f,c}=editor(view(),load);
    f.componentRef.setInput('classKey','sorcerer'); f.detectChanges();
    first.next(view()); expect(c.view()).toBeNull();
    const data=view(); data.grants=[]; second.next(data); f.detectChanges();
    expect(c.view().grants).toEqual([]); expect(c.complete()).toBe(true);
  });
  it('blocks on failed loading and retries explicitly', () => {
    const load=vi.fn().mockReturnValueOnce(throwError(()=>({error:{message:'Accès refusé'}}))).mockReturnValueOnce(of(view()));
    const {f,c}=editor(view(),load);
    expect(c.complete()).toBe(false); expect(f.nativeElement.textContent).toContain('Accès refusé');
    c.retry(); f.detectChanges(); expect(c.view()).not.toBeNull();
  });
  it('saves MJ repair with fingerprint and reason, preserves edits on 422 and blocks stale 409', () => {
    let request=new Subject<FeatureChoiceView>(); const save=vi.fn(()=>request);
    const {f,c}=editor(view(),undefined,save); c.reason.set('Choix confirmé'); c.select(c.view().grants[0],1,'defense');
    c.confirm(); c.confirm(); expect(save).toHaveBeenCalledTimes(1);
    expect(save.mock.calls[0]).toEqual([{fingerprint:'v1',reason:'Choix confirmé',selections:[{grantKey:'style-1',optionKey:'defense',slot:1}]}]);
    request.error({status:422,error:{message:'Prérequis manquant'}}); f.detectChanges();
    expect(c.selections()).toHaveLength(1); expect(f.nativeElement.textContent).toContain('Prérequis manquant');
    request=new Subject(); c.confirm(); request.error({status:409,error:{message:'Choix périmés'}}); f.detectChanges();
    expect(c.stale()).toBe(true); c.confirm(); expect(save).toHaveBeenCalledTimes(2);
  });
  it('refreshes after a successful MJ save and emits the profile refresh event', () => {
    const result=view(); result.fingerprint='v2'; const save=vi.fn(()=>of(result));
    const {c}=editor(view(),undefined,save); const saved=vi.fn(); c.saved.subscribe(saved);
    c.reason.set('Historique'); c.select(c.view().grants[0],1,'defense'); c.confirm();
    expect(saved).toHaveBeenCalledOnce(); expect(c.view().fingerprint).toBe('v2'); expect(c.selections()).toEqual([]);
  });
  it('integrates into the existing level-up payload without changing spell or HP choices', () => {
    const f=TestBed.createComponent(CharacterLevelUp);
    f.componentRef.setInput('character',{id:1,totalLevel:0,hitPoints:{maximumValue:10},abilities:[],training:{proficiencies:[],languages:[],expertises:[]}});
    f.componentRef.setInput('abilities',[]); f.componentRef.setInput('feats',[]);
    f.componentRef.setInput('options',{classes:[{id:2,slug:'fighter',name:'Guerrier',currentLevel:0,nextLevel:1,averageHitPointGain:6,eligible:true,subclasses:[],currentSubclass:null,subclassRequired:false,advancementRequired:false}]});
    f.componentRef.setInput('featurePreview',()=>of(view())); f.detectChanges();
    const c:any=f.componentInstance, emitted=vi.fn(); c.submitted.subscribe(emitted);
    c.submit(); expect(emitted).not.toHaveBeenCalled();
    c.featureChanged({complete:true,selections:[{grantKey:'style-1',optionKey:'defense',slot:1}]}); c.submit();
    expect(emitted.mock.calls[0][0]).toMatchObject({classId:2,hitPoints:{method:'average'},featureChoices:[{grantKey:'style-1',optionKey:'defense',slot:1}]});
    c.classChanged(); c.submit(); expect(emitted).toHaveBeenCalledTimes(1);
  });
});
describe('Feature choice API', () => {
  it('uses scoped GM/public routes and exact preview/repair payloads', () => {
    TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting()]});
    const api=TestBed.inject(FeatureChoicesApi), http=TestBed.inject(HttpTestingController);
    api.preview(2,7,'warlock','fiend').subscribe();
    const gm=http.expectOne('/api/campaigns/2/characters/7/feature-choices/preview'); expect(gm.request.body).toEqual({classKey:'warlock',subclassKey:'fiend'}); gm.flush(view());
    api.playerPreview('token','fighter',null).subscribe();
    const player=http.expectOne('/api/public/characters/token/feature-choices/preview'); expect(player.request.body).toEqual({classKey:'fighter',subclassKey:null}); player.flush(view());
    api.get(2,7).subscribe(); http.expectOne('/api/campaigns/2/characters/7/feature-choices').flush(view());
    api.repair(2,7,{fingerprint:'v1',reason:'MJ',selections:[]}).subscribe();
    const repair=http.expectOne('/api/campaigns/2/characters/7/feature-choices'); expect(repair.request.method).toBe('POST'); repair.flush(view()); http.verify();
  });
});

describe('First-level builder choices', () => {
  it('requires the preview selections and forwards them on creation', () => {
    const pending=new Subject<any>();
    const api={options:vi.fn(()=>of({proficiencyRules:[],languageRules:[],expertiseRules:[],featureChoices:view()})),create:vi.fn(()=>pending)};
    TestBed.configureTestingModule({providers:[
      {provide:ActivatedRoute,useValue:{snapshot:{paramMap:convertToParamMap({campaignId:'2'})}}},
      {provide:CharacterBuilderApiService,useValue:api},
      {provide:DndReferenceApiService,useValue:{getCampaignReference:()=>of({races:[],classes:[],abilities:[],feats:[]})}},
    ]});
    TestBed.overrideComponent(CharacterBuilder,{set:{template:''}});
    const f=TestBed.createComponent(CharacterBuilder), c:any=f.componentInstance;
    c.form.patchValue({slug:'hero',name:'Hero',raceId:1,classId:2});
    c.refreshOptions(true); c.submit(); expect(api.create).not.toHaveBeenCalled();
    c.loadFeatures()('',null).subscribe((result:FeatureChoiceView)=>expect(result.grants[0].newAtLevel).toBe(true));
    c.featureChanged({complete:true,selections:[{grantKey:'style-1',optionKey:'defense',slot:1}]}); c.submit();
    expect(api.create.mock.calls[0]).toEqual([2,expect.objectContaining({classId:2,featureChoices:[{grantKey:'style-1',optionKey:'defense',slot:1}]})]);
    pending.error({error:{message:'Choix refusé'}});
    expect(c.error()).toBe('Choix refusé'); expect(c.featureSelection()).toHaveLength(1);
  });
});

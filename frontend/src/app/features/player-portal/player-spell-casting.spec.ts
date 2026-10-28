import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PlayerSpells } from './components/player-spells/player-spells';
import { PlayerPortal } from './player-portal';
import { installNativeDialogStub } from '../../../testing/native-dialog';
import { CharacterStateService } from '@core/services/character-state.service';
import { CampaignConfigurationRegistryService } from '@core/services/campaign-configuration-registry.service';
import { RestRequestApiService } from '@core/services/rest-request-api.service';
import type { PlayerSpell } from '@core/services/character-session-state-api.service';
import type { SpellCastingAvailability, SpellCastMethod } from '@core/models/spell-cast.model';

installNativeDialogStub();

const base='/api/public/characters/test/spells';
const method=(kind: SpellCastMethod, level: number, status: 'available'|'unknown'|'unavailable'='available'): SpellCastingAvailability['methods'][number] =>
  ({kind, castingLevel:level, status, reason:null, cost:['cantrip','ritual'].includes(kind)?0:1,
    poolSlug:kind==='standard-slot'?`spell-slot-${level}`:kind==='pact-slot'?'warlock-pact-slots':null});
function spell(key='acquisition:1', methods=[method('standard-slot',1)]): PlayerSpell {
  return { key, spellId:1, policyId:1, name:'Sort partagé', level:1, school:'divination', source:'Magicien', sourceType:'class',
    castingAbility:'intelligence', spellSaveDc:12, spellAttackBonus:4, known:true, inSpellbook:true, prepared:true,
    castingAvailability:{sourceKey:key,policyId:1,status:'available',reason:null,methods} };
}
function hpSpell(kind: 'aid' | 'feast', levels = kind === 'aid' ? [2, 4] : [6, 7, 8, 9]): PlayerSpell {
  return { ...spell('acquisition:1', levels.map(level => method('standard-slot', level))),
    name: 'Nom volontairement différent', level: kind === 'aid' ? 2 : 6,
    details: { importKey: kind === 'aid' ? 'aidedd-2014:aide' : 'aidedd-2014:festin-des-heros',
      description: '', higherLevels: null, castingTime: '1 action', range: '9 m', duration: '8 heures',
      verbal: true, somatic: true, material: false, materialDescription: null, concentration: false, ritual: false } };
}
const view=(spells:PlayerSpell[])=>({revision:42,editable:true,spellPreparationAllowed:false,preparations:[],choices:[],warnings:[],spells});
function session(spells:PlayerSpell[],revision=42,current=2): any {
  return {id:1,revision,participating:true,campaign:{id:2,configurationKey:'strahd'},session:{id:3,name:'Session',status:'live'},
    levelUpAllowed:false,spells,activeEffects:[],updatedAt:'today',
    character:{slug:'test',name:'Test',type:'player',totalLevel:1,hitPoints:{maximumValue:10},classSummary:[],progressions:[],
      abilities:[],feats:[],actions:[],features:[],proficiencyBonus:2,
      resources:[{slug:'spell-slot-1',name:'Emplacements',maximum:2,rechargeType:'long-rest'}],definition:{}},
    state:{hitPoints:{current:10,temporary:0},hitDice:[],progressions:[],resources:[{id:'spell-slot-1',currentValue:current}]}};
}
function castResponse(spells:PlayerSpell[],kind:SpellCastMethod,level:number,revision=43): any {
  const option=method(kind,level);
  return {...session(spells,revision,1),cast:{source:{key:spells[0].key,spellId:spells[0].spellId,policyId:spells[0].policyId ?? null,sourceType:spells[0].sourceType},
    method:kind,castingLevel:level,poolSlug:option.poolSlug,cost:option.cost},replayed:false};
}
async function setup(spells:PlayerSpell[]) {
  TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting(),provideRouter([])]});
  const fixture=TestBed.createComponent(PlayerSpells);
  fixture.componentRef.setInput('spells',spells); fixture.componentRef.setInput('revision',42);
  fixture.componentRef.setInput('accessToken','test'); fixture.componentRef.setInput('readonly',false);
  fixture.detectChanges();
  const http=TestBed.inject(HttpTestingController); http.expectOne(base).flush(view(spells));
  await fixture.whenStable();
  fixture.componentInstance.select(spells[0]); fixture.detectChanges();
  return {fixture,http,c:fixture.componentInstance};
}
afterEach(()=>{ TestBed.inject(HttpTestingController).verify({ignoreCancelled:true}); TestBed.resetTestingModule(); vi.restoreAllMocks(); vi.unstubAllGlobals(); });

describe('Spell casting controls',()=>{
  it('selects 1 to 3 Aid targets, allows self-targeting and previews the selected level bonus', async () => {
    const {fixture,http,c}=await setup([hpSpell('aid',[1,2,4])]);
    c.openCastDialog(c.spells()[0]); fixture.detectChanges();
    const confirm=()=>fixture.nativeElement.querySelector('.cast-confirm') as HTMLButtonElement;
    expect(confirm().disabled).toBe(true);
    expect(fixture.nativeElement.textContent).toContain('Chargement des participants');
    http.expectOne('/api/public/characters/test/action-targets').flush({targets:[1,2,3,4].map(id=>({id,name:id===1?'Moi':`Cible ${id}`}))});
    await fixture.whenStable();
    const levels=fixture.nativeElement.querySelectorAll('.level-option');
    expect(levels).toHaveLength(2); levels[1].click(); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.hp-bonus').textContent).toContain('+15 PV actuels et maximum');
    const boxes=fixture.nativeElement.querySelectorAll('app-spell-target-picker input') as NodeListOf<HTMLInputElement>;
    boxes[0].click(); fixture.detectChanges(); expect(confirm().disabled).toBe(false);
    boxes[1].click(); boxes[2].click(); fixture.detectChanges(); expect(boxes[3].disabled).toBe(true);
    boxes[1].click(); fixture.detectChanges(); expect(boxes[3].disabled).toBe(false);
    boxes[3].click(); fixture.detectChanges(); confirm().click();
    const request=http.expectOne(base+'/cast');
    expect(request.request.body).toEqual({revision:42,requestId:expect.any(String),sourceKey:'acquisition:1',method:'standard-slot',castingLevel:4,targetCharacterIds:[1,3,4]});
    request.flush(castResponse(c.spells(),'standard-slot',4));
  });

  it('requires 1 to 12 Feast participants and an integer 2d10 result, independent of upcasting', async () => {
    const {fixture,http,c}=await setup([hpSpell('feast')]);
    c.openCastDialog(c.spells()[0]); fixture.detectChanges();
    http.expectOne('/api/public/characters/test/action-targets').flush({targets:Array.from({length:13},(_,i)=>({id:i+1,name:`Cible ${i+1}`}))});
    await fixture.whenStable();
    const levels=fixture.nativeElement.querySelectorAll('.level-option'); expect(levels).toHaveLength(4); levels[3].click();
    const boxes=fixture.nativeElement.querySelectorAll('app-spell-target-picker input') as NodeListOf<HTMLInputElement>;
    for(let i=0;i<12;i++) boxes[i].click(); fixture.detectChanges(); expect(boxes[12].disabled).toBe(true);
    const roll=fixture.nativeElement.querySelector('#spell-cast-hit-point-roll') as HTMLInputElement;
    const confirm=()=>fixture.nativeElement.querySelector('.cast-confirm') as HTMLButtonElement;
    for(const value of ['', '1','21','2.5']) {
      roll.value=value; roll.dispatchEvent(new Event('input')); fixture.detectChanges(); expect(confirm().disabled).toBe(true);
    }
    for(const value of ['2','20']) {
      roll.value=value; roll.dispatchEvent(new Event('input')); fixture.detectChanges(); expect(confirm().disabled).toBe(false);
      expect(fixture.nativeElement.querySelector('.hp-bonus').textContent).toContain(`+${value} PV actuels et maximum`);
    }
    levels[0].click(); fixture.detectChanges(); expect(fixture.nativeElement.querySelector('.hp-bonus').textContent).toContain('+20');
    confirm().click(); const req=http.expectOne(base+'/cast');
    expect(req.request.body).toEqual({revision:42,requestId:expect.any(String),sourceKey:'acquisition:1',method:'standard-slot',castingLevel:6,targetCharacterIds:Array.from({length:12},(_,i)=>i+1),hitPointRoll:20});
    req.flush(castResponse(c.spells(),'standard-slot',6));
  });

  it('keeps confirmation disabled on target loading failure and an empty retry', async () => {
    const {fixture,http,c}=await setup([hpSpell('aid',[2])]); c.openCastDialog(c.spells()[0]); fixture.detectChanges();
    http.expectOne('/api/public/characters/test/action-targets').flush({}, {status:500,statusText:'Error'});
    await fixture.whenStable(); expect(fixture.nativeElement.textContent).toContain('Impossible de charger');
    expect(fixture.nativeElement.querySelector('.cast-confirm').disabled).toBe(true);
    fixture.nativeElement.querySelector('app-spell-target-picker button').click(); fixture.detectChanges();
    http.expectOne('/api/public/characters/test/action-targets').flush({targets:[]}); await fixture.whenStable();
    expect(fixture.nativeElement.textContent).toContain('Aucun participant disponible');
    expect(fixture.nativeElement.querySelector('.cast-confirm').disabled).toBe(true); http.expectNone(base+'/cast');
  });

  it.each([undefined,null,'other:aid'])('does not infer an effect from the displayed name when importKey is %s', async importKey => {
    const entry=hpSpell('aid',[2]); entry.name='Aide'; entry.details!.importKey=importKey;
    const {fixture,http,c}=await setup([entry]); c.openCastDialog(entry); fixture.detectChanges();
    http.expectNone('/api/public/characters/test/action-targets');
    fixture.nativeElement.querySelector('.cast-confirm').click(); const req=http.expectOne(base+'/cast');
    expect(Object.keys(req.request.body).sort()).toEqual(['revision','requestId','sourceKey','method','castingLevel'].sort());
    req.flush(castResponse([entry],'standard-slot',2));
  });
  it('opens an always-prepared oath spell with slot methods and no implicit free casting',async()=>{
    const entry={...spell('subclass-grant:7',[method('standard-slot',1),method('standard-slot',4)]),
      policyId:null,sourceType:'subclass' as const,source:'Vengeance',castingAbility:'charisma',
      known:false,inSpellbook:false,prepared:true,alwaysPrepared:true};
    entry.castingAvailability!.policyId=null;
    const {fixture,http,c}=await setup([entry]);
    fixture.nativeElement.querySelector('.quick-spell').click(); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('dialog').open).toBe(true);
    expect(c.castMethods().every(m=>m.kind==='standard-slot' && m.cost===1)).toBe(true);
    fixture.nativeElement.querySelectorAll('.level-option')[1].click(); fixture.detectChanges();
    fixture.nativeElement.querySelector('.cast-confirm').click();
    const req=http.expectOne(base+'/cast');
    expect(req.request.body).toMatchObject({sourceKey:entry.key,method:'standard-slot',castingLevel:4});
    req.flush(castResponse([entry],'standard-slot',4));
    expect(c.pendingCast()).toBeNull();
    http.expectNone(r=>r.method==='PATCH');
  });
  it('sends a feature resource command only after confirmation and adopts its server result',async()=>{
    const resource = {...method('resource',2),poolSlug:'feat-use',cost:2};
    const entry = {...spell('feature:1',[resource,method('standard-slot',2)]),sourceType:'feature' as const,inSpellbook:false};
    const {fixture,http,c}=await setup([entry]);
    fixture.nativeElement.querySelector('.quick-spell').click(); fixture.detectChanges();
    http.expectNone(r=>r.method!=='GET');
    expect(fixture.nativeElement.querySelector('.cast-confirm').disabled).toBe(true);
    fixture.nativeElement.querySelector('[data-method=resource]').click(); fixture.detectChanges();
    http.expectNone(r=>r.method!=='GET');
    fixture.nativeElement.querySelector('.cast-confirm').click();
    const req=http.expectOne(base+'/cast');
    expect(req.request.body).toMatchObject({revision:42,sourceKey:'feature:1',method:'resource',castingLevel:2});
    expect(req.request.body.cost).toBeUndefined(); expect(req.request.body.poolSlug).toBeUndefined();
    const result=castResponse([entry],'resource',2); result.cast.cost=2; result.cast.poolSlug='feat-use';
    result.state.resources.push({id:'feat-use',currentValue:0});
    const done=vi.fn(); c.castCompleted.subscribe(done); req.flush(result);
    expect(done).toHaveBeenCalledWith(result); expect(c.pendingCast()).toBeNull();
    http.expectNone(r=>r.method==='PATCH');
  });
  it('passes existing resource counts for display, hiding them when the directory revision is newer',async()=>{
    const {fixture,http,c}=await setup([spell()]);
    fixture.componentRef.setInput('resources',[{id:'spell-slot-1',currentValue:2}]); fixture.detectChanges();
    fixture.nativeElement.querySelector('.cast-open').click(); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.level-option small').textContent).toContain('2 rest.');
    c.view.set({...view([spell()]),revision:43}); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.level-option small').textContent).toContain('? rest.');
    http.expectNone(r=>r.method!=='GET');
  });
  it('opens from the Sorts quicklist and cancels without a command, UUID or write',async()=>{
    const uuid=vi.spyOn(crypto,'randomUUID');
    const {fixture,http,c}=await setup([spell()]);
    fixture.nativeElement.querySelector('.quick-spell').click(); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('dialog').open).toBe(true);
    fixture.nativeElement.querySelector('app-spell-cast-dialog .cancel').click(); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('dialog')).toBeNull();
    expect(c.pendingCast()).toBeNull(); expect(uuid).not.toHaveBeenCalled();
    http.expectNone(r=>r.method!=='GET');
  });
  it('executes the exact source and level explicitly selected in the shared modal',async()=>{
    const entries=[spell('wizard',[method('standard-slot',1),method('standard-slot',3)]),
      {...spell('bard',[method('pact-slot',3)]),source:'Barde',policyId:2}];
    const {fixture,http,c}=await setup(entries);
    fixture.nativeElement.querySelector('.cast-open').click(); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.cast-confirm').disabled).toBe(true);
    const source=fixture.nativeElement.querySelector('app-spell-cast-dialog select') as HTMLSelectElement;
    source.value='bard'; source.dispatchEvent(new Event('change')); fixture.detectChanges();
    fixture.nativeElement.querySelector('.cast-confirm').click();
    const req=http.expectOne(base+'/cast');
    expect(req.request.body).toMatchObject({revision:42,sourceKey:'bard',method:'pact-slot',castingLevel:3});
    req.flush(castResponse([entries[1],entries[0]],'pact-slot',3)); fixture.detectChanges();
    expect(c.pendingCast()).toBeNull(); expect(fixture.nativeElement.querySelector('.success').textContent).toContain('Barde');
    expect(fixture.nativeElement.querySelector('.cast-confirm')).toBeNull();
    http.expectNone(r=>r.method==='PATCH');
  });
  it.each([['cantrip',0],['ritual',1],['standard-slot',1],['standard-slot',3],['pact-slot',3]] as const)('confirms %s at server-authorized level %s',async(kind,level)=>{
    const entry=spell('acquisition:1',[method(kind,level)]);
    if(kind==='cantrip') entry.level=0;
    if(kind==='ritual'){entry.known=false;entry.prepared=false;}
    const {fixture,http,c}=await setup([entry]);
    fixture.nativeElement.querySelector('.cast-open').click(); fixture.detectChanges();
    const option=fixture.nativeElement.querySelector('.cast-option') as HTMLButtonElement;
    expect(option).not.toBeNull(); option.click(); fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain(kind==='cantrip'||kind==='ritual'?'Aucun emplacement consommé':'Consommer 1 emplacement');
    const button=fixture.nativeElement.querySelector('.cast-confirm') as HTMLButtonElement;
    button.click(); button.click(); fixture.detectChanges();
    const req=http.expectOne(base+'/cast');
    expect(req.request.body).toMatchObject({revision:42,sourceKey:entry.key,method:kind,castingLevel:level});
    expect(c.castSaving()).toBe(true);
    expect(c.displayed()[0]).toEqual(entry);
    const done=vi.fn(); c.castCompleted.subscribe(done);
    const result=castResponse([entry],kind,level); req.flush(result); fixture.detectChanges();
    expect(done).toHaveBeenCalledWith(result); expect(c.castSuccess()).toContain('lancement confirmé');
    expect(c.pendingCast()).toBeNull(); http.expectNone(r=>r.method==='PATCH');
  });
  it('keeps multiclass sources separate and filters methods, not the global status',async()=>{
    const wizard=spell('acquisition:1',[method('standard-slot',1,'unknown')]);
    const bard={...spell('acquisition:2',[method('pact-slot',2)]),source:'Barde',policyId:2};
    bard.castingAvailability!.status='unknown'; // only individual method matters
    const {fixture,http,c}=await setup([wizard,bard]);
    expect(c.castMethods()).toEqual([]); expect(fixture.nativeElement.querySelector('.cast-confirm')).toBeNull();
    c.select(bard); c.castChoice.set('pact-slot:2'); c.confirmCast();
    const req=http.expectOne(base+'/cast'); expect(req.request.body.sourceKey).toBe(bard.key);
    req.flush(castResponse([bard,wizard],'pact-slot',2));
  });
  it('never offers unknown, exhausted, unresolved or mismatched sources',async()=>{
    const entry=spell('feature:1',[method('standard-slot',1,'unavailable'),method('pact-slot',3,'unknown')]);
    entry.sourceType='feature';
    const {fixture,c}=await setup([entry]);
    expect(c.castMethods()).toEqual([]); c.castChoice.set('standard-slot:1'); c.confirmCast();
    expect(c.pendingCast()).toBeNull();
    fixture.componentRef.setInput('spells',[{...entry,castingAvailability:{...entry.castingAvailability!,sourceKey:'other',methods:[method('cantrip',0)]}}]);
    fixture.detectChanges(); expect(c.castMethods()).toEqual([]);
  });
  it('retries the exact pending command after network failure despite new revision and selection',async()=>{
    const uuid=vi.spyOn(crypto,'randomUUID'); const entries=[spell(),spell('acquisition:2')];
    const {fixture,http,c}=await setup(entries); c.castChoice.set('standard-slot:1'); c.confirmCast();
    const first=http.expectOne(base+'/cast'); const original=first.request.body;
    first.error(new ProgressEvent('error')); expect(c.castUncertain()).toBe(true);
    c.select(entries[1]); c.castChoice.set('standard-slot:1'); c.confirmCast(); http.expectNone(base+'/cast');
    fixture.componentRef.setInput('revision',45); fixture.detectChanges();
    http.expectOne(base).flush({...view(entries),revision:45});
    c.retryCast(); c.retryCast(); const retry=http.expectOne(base+'/cast'); expect(retry.request.body).toEqual(original);
    retry.flush({...castResponse(entries,'standard-slot',1,46),replayed:true});
    expect(uuid).toHaveBeenCalledTimes(1); expect(c.castSuccess()).toContain('déjà enregistré');
  });
  it.each([400,404,409,422])('requires explicit dismissal after %s without retry or revision rewrite',async status=>{
    const {c,http}=await setup([spell()]); c.castChoice.set('standard-slot:1'); c.confirmCast();
    const req=http.expectOne(base+'/cast'); const original=req.request.body;
    req.flush({message:'refused'},{status,statusText:'Refused'});
    c.retryCast(); c.confirmCast(); http.expectNone(base+'/cast');
    expect(c.pendingCast()?.command).toEqual(original); expect(c.castError()).not.toBe('');
    c.dismissCastRefusal(); expect(c.pendingCast()).toBeNull(); expect(c.castChoice()).toBe('');
  });
  it('warns before losing an uncertain command',async()=>{
    const {c,http}=await setup([spell()]); c.castChoice.set('standard-slot:1'); c.confirmCast();
    http.expectOne(base+'/cast').error(new ProgressEvent('error'));
    vi.spyOn(window,'confirm').mockReturnValue(false); expect(c.canLeavePreparation()).toBe(false);
    const event=new Event('beforeunload',{cancelable:true}); c.protectPendingCast(event as BeforeUnloadEvent);
    expect(event.defaultPrevented).toBe(true);
  });
  it('blocks confirmation while local edits are saving and never invents a slot level',async()=>{
    const {fixture,c,http}=await setup([spell('acquisition:1',[method('standard-slot',1),method('pact-slot',3)])]);
    c.castChoice.set('standard-slot:2'); c.confirmCast(); http.expectNone(base+'/cast');
    c.castChoice.set('pact-slot:2'); c.confirmCast(); http.expectNone(base+'/cast');
    fixture.componentRef.setInput('castingBlocked',true); fixture.detectChanges();
    c.castChoice.set('standard-slot:1'); c.confirmCast(); http.expectNone(base+'/cast');
    expect(c.pendingCast()).toBeNull();
  });
  it('keeps a command after HTTP 500 and refreshes only explicitly after a refusal',async()=>{
    const {c,http}=await setup([spell()]); c.castChoice.set('standard-slot:1'); c.confirmCast();
    const original=http.expectOne(base+'/cast'); const command=original.request.body;
    original.flush({message:'Server error'},{status:500,statusText:'Error'});
    const changed=vi.fn(); c.changed.subscribe(changed); c.refreshAfterCastRefusal();
    expect(changed).not.toHaveBeenCalled(); expect(c.pendingCast()?.command).toEqual(command);
    c.retryCast(); const replay=http.expectOne(base+'/cast'); expect(replay.request.body).toEqual(command);
    replay.flush({message:'Conflict'},{status:409,statusText:'Conflict'});
    c.refreshAfterCastRefusal(); expect(changed).toHaveBeenCalledTimes(1);
    expect(c.pendingCast()).toBeNull(); http.expectNone(base+'/cast');
  });
});

describe('Portal adoption of cast responses',()=>{
  it.each([false,true])('ignores retired preparation handlers and preserves other preparable actions (other=%s)', async other => {
    vi.stubGlobal('matchMedia',vi.fn(()=>({matches:false,addEventListener:vi.fn(),removeEventListener:vi.fn()})));
    TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting(),provideRouter([]),
      {provide:ActivatedRoute,useValue:{snapshot:{paramMap:convertToParamMap({campaignId:'2',sessionId:'3',accessToken:'test'}),queryParamMap:convertToParamMap({tab:'status'})}}},
      {provide:CampaignConfigurationRegistryService,useValue:{getConfiguration:()=>({id:'2',name:'Test'})}},
      {provide:RestRequestApiService,useValue:{getLatest:()=>of(null)}}]});
    const fixture=TestBed.createComponent(PlayerPortal); const p=fixture.componentInstance as any;
    const http=TestBed.inject(HttpTestingController);
    http.expectOne('/api/public/characters/test').flush(session([hpSpell('aid',[2])]));
    const legacy=[{slug:'renamed-aid',name:'Aide',handlerType:'aid',requiresPreparation:true},
      {slug:'renamed-feast',name:'Festin',handlerType:'heroes-feast',requiresPreparation:true}];
    const retained={slug:'other',name:'Autre préparation',handlerType:'arcane-recovery',requiresPreparation:true};
    p.actions.set(other?[...legacy,retained]:legacy);
    p.characterActions.set({prepared:['renamed-aid','renamed-feast',...(other?['other']:[])],preparationPending:true});
    fixture.detectChanges(); await fixture.whenStable();
    expect(p.preparationActions()).toEqual(other?[retained]:[]);
    expect(p.preparedActions()).toEqual(other?[retained]:[]);
    expect(p.preparationPending()).toBe(other);
    expect(fixture.nativeElement.querySelectorAll('app-action-preparation-modal')).toHaveLength(other?1:0);
    for (const action of legacy) p.openAction(action);
    http.expectNone('/api/public/characters/test/action-targets');
    expect(fixture.nativeElement.querySelector('app-aid-action-modal, app-heroes-feast-action-modal')).toBeNull();
    fixture.destroy();
  });

  it.each([
    ['status','aid'], ['spells','aid'], ['status','feast'], ['spells','feast'],
  ] as const)('casts %s / %s through the sole dialog and preserves every parameter during an uncertain retry', async (tab,kind) => {
    vi.stubGlobal('matchMedia',vi.fn(()=>({matches:false,addEventListener:vi.fn(),removeEventListener:vi.fn()})));
    TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting(),provideRouter([]),
      {provide:ActivatedRoute,useValue:{snapshot:{paramMap:convertToParamMap({campaignId:'2',sessionId:'3',accessToken:'test'}),queryParamMap:convertToParamMap({tab})}}},
      {provide:CampaignConfigurationRegistryService,useValue:{getConfiguration:()=>({id:'2',name:'Test'})}},
      {provide:RestRequestApiService,useValue:{getLatest:()=>of(null)}}]});
    const level=kind==='aid'?2:6;
    const entry=hpSpell(kind,[level]);
    entry.sourceType=kind==='aid'?'feature':'subclass'; entry.key=kind==='aid'?'feature:13':'subclass-grant:7';
    entry.castingAvailability!.sourceKey=entry.key;
    const entries=[entry]; const fixture=TestBed.createComponent(PlayerPortal); const p=fixture.componentInstance as any;
    const http=TestBed.inject(HttpTestingController);
    const initial=session(entries);
    initial.character.resources=[{slug:`spell-slot-${level}`,name:'Emplacements',maximum:2,rechargeType:'long-rest'}];
    initial.state.resources=[{id:`spell-slot-${level}`,currentValue:2}];
    http.expectOne('/api/public/characters/test').flush(initial); fixture.detectChanges();
    for (const req of http.match(base)) req.flush(view(entries)); await fixture.whenStable();
    fixture.nativeElement.querySelector('.quick-spell').click(); fixture.detectChanges();
    for (const req of http.match(base)) req.flush(view(entries)); await fixture.whenStable();
    expect(p.activeTab()).toBe(tab);
    expect(fixture.nativeElement.querySelectorAll('app-spell-cast-dialog')).toHaveLength(1);
    http.expectOne('/api/public/characters/test/action-targets').flush({targets:[{id:1,name:'Moi'},{id:2,name:'Compagnon'}]});
    await fixture.whenStable();
    fixture.nativeElement.querySelector('app-spell-target-picker input').click();
    if(kind==='feast') {
      const input=fixture.nativeElement.querySelector('#spell-cast-hit-point-roll'); input.value='12'; input.dispatchEvent(new Event('input'));
    }
    fixture.detectChanges(); fixture.nativeElement.querySelector('.cast-confirm').click();
    const first=http.expectOne(base+'/cast'); const original=structuredClone(first.request.body);
    expect(original).toEqual({revision:42,requestId:expect.any(String),sourceKey:entry.key,method:'standard-slot',castingLevel:level,targetCharacterIds:[1],...(kind==='feast'?{hitPointRoll:12}:{})});
    const c=fixture.debugElement.query(By.directive(PlayerSpells)).componentInstance as PlayerSpells;
    first.error(new ProgressEvent('error')); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('app-spell-target-picker')).toBeNull();
    expect(fixture.nativeElement.querySelector('#spell-cast-hit-point-roll')).toBeNull();
    expect(fixture.nativeElement.querySelector('.level-option')).toBeNull();
    c.confirmDialogSelection({sourceKey:entry.key!,method:'standard-slot',castingLevel:9,targetCharacterIds:[2],hitPointRoll:20});
    expect(c.pendingCast()?.command).toEqual(original); http.expectNone(base+'/cast');
    fixture.nativeElement.querySelector('app-spell-cast-dialog .cancel').click(); fixture.detectChanges();
    p.selectTab(tab==='status'?'spells':'status'); fixture.detectChanges();
    c.dialogOpen.set(true); fixture.detectChanges();
    http.expectNone('/api/public/characters/test/action-targets');
    fixture.nativeElement.querySelector('app-spell-cast-dialog .retry').click();
    const retry=http.expectOne(base+'/cast'); expect(retry.request.body).toEqual(original);
    retry.flush({...initial,revision:43,cast:{source:{key:entry.key,spellId:1,policyId:1,sourceType:entry.sourceType},method:'standard-slot',castingLevel:level,poolSlug:`spell-slot-${level}`,cost:1},replayed:true});
    await fixture.whenStable(); expect(c.pendingCast()).toBeNull();
    http.expectNone(r=>r.method==='PATCH'); fixture.destroy();
  });

  it('retains pending commands across tabs and adopts a replay without a PATCH or local consumption',async()=>{
    vi.stubGlobal('matchMedia',vi.fn(()=>({matches:false,addEventListener:vi.fn(),removeEventListener:vi.fn()})));
    TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting(),provideRouter([]),
      {provide:ActivatedRoute,useValue:{snapshot:{paramMap:convertToParamMap({campaignId:'2',sessionId:'3',accessToken:'test'}),queryParamMap:convertToParamMap({tab:'status'})}}},
      {provide:CampaignConfigurationRegistryService,useValue:{getConfiguration:()=>({id:'2',name:'Test'})}},
      {provide:RestRequestApiService,useValue:{getLatest:()=>of(null)}}]});
    const entries=[spell()]; const fixture=TestBed.createComponent(PlayerPortal); const p=fixture.componentInstance as any;
    const http=TestBed.inject(HttpTestingController); const state=TestBed.inject(CharacterStateService);
    const initial=session(entries);
    initial.character.resources.push({slug:'second-wind',name:'Second souffle',maximum:1,rechargeType:'short-rest'});
    initial.state.resources.push({id:'second-wind',currentValue:1});
    http.expectOne('/api/public/characters/test').flush(initial); fixture.detectChanges();
    expect(fixture.debugElement.query(By.directive(PlayerSpells))).toBeNull();
    expect(fixture.nativeElement.querySelector('app-spell-slot-bar .slot-group').getAttribute('aria-label')).toContain('2 sur 2');
    expect(p.trackerResources().some((r: any)=>r.id==='spell-slot-1')).toBe(false);
    expect(p.trackerResources().map((r: any)=>r.id)).toEqual(['second-wind']);
    const quick=fixture.nativeElement.querySelector('.spell-resource-column .quick-spell') as HTMLButtonElement;
    expect(quick.disabled).toBe(false); quick.click(); fixture.detectChanges();
    expect(p.activeTab()).toBe('status');
    http.expectNone(r=>r.method==='POST' || r.method==='PATCH');
    http.expectOne(base).flush(view(entries)); await fixture.whenStable();
    const c=fixture.debugElement.query(By.directive(PlayerSpells)).componentInstance as PlayerSpells;
    expect(fixture.nativeElement.querySelector('.level-option small').textContent).toContain('2 rest.');
    expect(c.selectedSpell()?.key).toBe(entries[0].key);
    const confirm=fixture.nativeElement.querySelector('.cast-confirm') as HTMLButtonElement;
    expect(confirm.disabled).toBe(false); confirm.click(); confirm.click();
    const first=http.expectOne(base+'/cast'); const original=first.request.body;
    expect(state.character()!.resources.find(r=>r.id==='spell-slot-1')!.currentValue).toBe(2);
    first.error(new ProgressEvent('error')); fixture.detectChanges();
    fixture.nativeElement.querySelector('app-spell-cast-dialog .cancel').click(); fixture.detectChanges();
    p.selectTab('features'); fixture.detectChanges();
    p.selectTab('status'); fixture.detectChanges();
    (fixture.nativeElement.querySelector('.spell-resource-column .quick-spell') as HTMLButtonElement).click(); fixture.detectChanges();
    expect(fixture.debugElement.query(By.directive(PlayerSpells)).componentInstance).toBe(c);
    expect(c.pendingCast()?.command).toEqual(original);
    // Later public data may change revision while the original command is retained.
    fixture.nativeElement.querySelector('app-spell-cast-dialog .retry').click();
    const retry=http.expectOne(base+'/cast'); expect(retry.request.body).toEqual(original);
    const result={...castResponse([spell('acquisition:1',[method('standard-slot',1,'unavailable')])],'standard-slot',1,47),replayed:true};
    retry.flush(result); await fixture.whenStable();
    expect(p.serverRevision).toBe(47);
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('app-spell-slot-bar .slot-group').getAttribute('aria-label')).toContain('1 sur 2');
    expect(state.character()!.resources.find(r=>r.id==='spell-slot-1')!.currentValue).toBe(1);
    p.selectTab('spells'); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('app-spell-slot-bar .slot-group').getAttribute('aria-label')).toContain('1 sur 2');
    expect(fixture.nativeElement.querySelectorAll('app-spell-slot-bar')).toHaveLength(1);
    expect(p.lastQueuedRevision).toBe(state.revision()); expect(c.castMethods()).toEqual([]);
    p.selectTab('status'); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.spell-resource-column .quick-spell').disabled).toBe(true);
    expect(c.pendingCast()).toBeNull();
    await new Promise(resolve=>setTimeout(resolve,450));
    http.expectNone(r=>r.method==='PATCH');
    for(const req of http.match('/api/public/characters/test')) req.flush(result);
    fixture.destroy();
  });
});

import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PlayerSpells } from './components/player-spells/player-spells';
import { PlayerPortal } from './player-portal';
import { CharacterStateService } from '@core/services/character-state.service';
import { CampaignConfigurationRegistryService } from '@core/services/campaign-configuration-registry.service';
import { RestRequestApiService } from '@core/services/rest-request-api.service';
import type { PlayerSpell } from '@core/services/character-session-state-api.service';
import type { SpellCastingAvailability, SpellCastMethod } from '@core/models/spell-cast.model';

const base='/api/public/characters/test/spells';
const method=(kind: SpellCastMethod, level: number, status: 'available'|'unknown'|'unavailable'='available'): SpellCastingAvailability['methods'][number] =>
  ({kind, castingLevel:level, status, reason:null, cost:['cantrip','ritual'].includes(kind)?0:1,
    poolSlug:kind==='standard-slot'?`spell-slot-${level}`:kind==='pact-slot'?'warlock-pact-slots':null});
function spell(key='acquisition:1', methods=[method('standard-slot',1)]): PlayerSpell {
  return { key, spellId:1, policyId:1, name:'Sort partagé', level:1, school:'divination', source:'Magicien', sourceType:'class',
    castingAbility:'intelligence', spellSaveDc:12, spellAttackBonus:4, known:true, inSpellbook:true, prepared:true,
    castingAvailability:{sourceKey:key,policyId:1,status:'available',reason:null,methods} };
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
  return {...session(spells,revision,1),cast:{source:{key:spells[0].key,spellId:1,policyId:1,sourceType:'class'},
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
  it.each([['cantrip',0],['ritual',1],['standard-slot',1],['standard-slot',3],['pact-slot',3]] as const)('confirms %s at server-authorized level %s',async(kind,level)=>{
    const entry=spell('acquisition:1',[method(kind,level)]);
    if(kind==='cantrip') entry.level=0;
    if(kind==='ritual'){entry.known=false;entry.prepared=false;}
    const {fixture,http,c}=await setup([entry]);
    const radio=fixture.nativeElement.querySelector('input[type=radio]') as HTMLInputElement;
    expect(radio).not.toBeNull(); radio.click(); fixture.detectChanges();
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
  it('retains pending commands across tabs and adopts a replay without a PATCH or local consumption',async()=>{
    vi.stubGlobal('matchMedia',vi.fn(()=>({matches:false,addEventListener:vi.fn(),removeEventListener:vi.fn()})));
    TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting(),provideRouter([]),
      {provide:ActivatedRoute,useValue:{snapshot:{paramMap:convertToParamMap({campaignId:'2',sessionId:'3',accessToken:'test'}),queryParamMap:convertToParamMap({tab:'status'})}}},
      {provide:CampaignConfigurationRegistryService,useValue:{getConfiguration:()=>({id:'2',name:'Test'})}},
      {provide:RestRequestApiService,useValue:{getLatest:()=>of(null)}}]});
    const entries=[spell()]; const fixture=TestBed.createComponent(PlayerPortal); const p=fixture.componentInstance as any;
    const http=TestBed.inject(HttpTestingController); const state=TestBed.inject(CharacterStateService);
    http.expectOne('/api/public/characters/test').flush(session(entries)); fixture.detectChanges();
    expect(fixture.debugElement.query(By.directive(PlayerSpells))).toBeNull();
    const quick=fixture.nativeElement.querySelector('.spell-resource-column .quick-spell') as HTMLButtonElement;
    expect(quick.disabled).toBe(false); quick.click(); fixture.detectChanges();
    http.expectNone(r=>r.method==='POST' || r.method==='PATCH');
    http.expectOne(base).flush(view(entries)); await fixture.whenStable();
    const c=fixture.debugElement.query(By.directive(PlayerSpells)).componentInstance as PlayerSpells;
    expect(c.selectedSpell()?.key).toBe(entries[0].key); c.castChoice.set('standard-slot:1'); c.confirmCast();
    const first=http.expectOne(base+'/cast'); const original=first.request.body;
    expect(state.character()!.resources.find(r=>r.id==='spell-slot-1')!.currentValue).toBe(2);
    first.error(new ProgressEvent('error')); fixture.detectChanges();
    p.selectTab('status'); fixture.detectChanges();
    (fixture.nativeElement.querySelector('.spell-resource-column .quick-spell') as HTMLButtonElement).click(); fixture.detectChanges();
    expect(fixture.debugElement.query(By.directive(PlayerSpells)).componentInstance).toBe(c);
    expect(c.pendingCast()?.command).toEqual(original);
    // Later public data may change revision while the original command is retained.
    c.retryCast(); const retry=http.expectOne(base+'/cast'); expect(retry.request.body).toEqual(original);
    const result={...castResponse([spell('acquisition:1',[method('standard-slot',1,'unavailable')])],'standard-slot',1,47),replayed:true};
    retry.flush(result); await fixture.whenStable();
    expect(p.serverRevision).toBe(47);
    expect(state.character()!.resources.find(r=>r.id==='spell-slot-1')!.currentValue).toBe(1);
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

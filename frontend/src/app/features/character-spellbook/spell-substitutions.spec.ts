import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { SpellSubstitutions } from './spell-substitutions';

describe('Individual spell substitutions', () => {
  let http: HttpTestingController;
  const url='/api/characters/7/spell-substitutions';
  const data={characterName:'Fixture',history:[],grants:[{
    grantId:3,sourceName:'Source personnelle',className:'Classe source',originalSpell:{id:10,name:'Initial'},currentSpell:{id:11,name:'Actuel'},
    expectedPrevious:12,activeRuleId:5,currentClassLevelId:20,
    versions:[{id:6,version:2,provenance:'Nouvelle règle',levels:[{id:20,level:5,remaining:1}],available:[{id:13,name:'Compatible'}]},
      {id:5,version:1,provenance:'Règle figée',levels:[{id:20,level:5,remaining:1}],available:[{id:13,name:'Compatible'}]}],
  }]};
  beforeEach(()=> {
    TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting(),provideRouter([]),
      {provide:ActivatedRoute,useValue:{snapshot:{paramMap:convertToParamMap({campaignId:'2',characterId:'7'})}}}]});
    http=TestBed.inject(HttpTestingController);
  });
  afterEach(()=>http.verify());
  async function load() {
    const f=TestBed.createComponent(SpellSubstitutions); http.expectOne(url).flush(data); f.detectChanges(); await f.whenStable();
    const select=f.nativeElement.querySelector('#grant') as HTMLSelectElement;
    select.selectedIndex=1; select.dispatchEvent(new Event('change')); await f.whenStable(); f.detectChanges(); return f;
  }
  it('shows the pinned rule rather than automatically using the latest version',async ()=>{
    const f=await load(); expect(f.componentInstance.ruleId).toBe(5); expect(f.nativeElement.textContent).toContain('Règle figée');
  });
  it('requires a selection, reason and explicit confirmation',async ()=>{
    const c=(await load()).componentInstance; c.spellId=13;c.levelId=20;c.save();http.expectNone(r=>r.method==='POST');
    c.reason='Motif';c.confirmed=true;c.save();
    const request=http.expectOne(url);expect(request.request.body.expectedPrevious).toBe(12);expect(request.request.body.kind).toBe('substitution');
    request.flush({id:15});http.expectOne(url).flush(data);expect(c.reason).toBe('');expect(c.confirmed).toBe(false);
  });
  it('preserves a conflicting draft and requires a new confirmation',async ()=>{
    const c=(await load()).componentInstance;c.spellId=13;c.levelId=20;c.reason='Conserver';c.confirmed=true;c.save();c.save();
    http.expectOne(url).flush({}, {status:409,statusText:'Conflict'});
    expect(c.reason).toBe('Conserver');expect(c.confirmed).toBe(false);expect(c.error()).toContain('Rechargez');
  });
  it('revokes by restoring the original spell at the current source level',async ()=>{
    const c=(await load()).componentInstance;c.kind='revocation';c.selectKind();c.reason='Rétablissement';c.confirmed=true;c.save();
    const r=http.expectOne(url);expect(r.request.body.spellId).toBe(10);expect(r.request.body.classLevelId).toBe(20);
    r.flush({}, {status:400,statusText:'Bad Request'});
  });
  it('transitions explicitly while preserving the current spell',async ()=>{
    const c=(await load()).componentInstance;c.kind='transition';c.selectKind();c.ruleId=6;c.reason='Version validée';c.confirmed=true;c.save();
    const r=http.expectOne(url);expect(r.request.body.spellId).toBe(11);expect(r.request.body.ruleId).toBe(6);
    r.flush({}, {status:400,statusText:'Bad Request'});
  });
  it('explains pending migration without inventing choices',async ()=>{
    const f=TestBed.createComponent(SpellSubstitutions);http.expectOne(url).flush({}, {status:503,statusText:'Unavailable'});
    expect(f.componentInstance.error()).toContain('migration');expect(f.componentInstance.options()).toBeNull();
  });
});

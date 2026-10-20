import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SpellRevision } from './spell-revision';

describe('GM acquisition revisions',()=>{
  let http:HttpTestingController;
  const base='/api/characters/12/spell-acquisitions';
  const data={ acquisitions:[{id:4,spell_id:7,spell_name:'Fiction'}],targets:[{id:3,label:'Version 1'},{id:5,label:'Version 2'}],history:[] };
  beforeEach(()=>TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting(),provideRouter([]),{provide:ActivatedRoute,useValue:{snapshot:{paramMap:convertToParamMap({campaignId:'2',characterId:'12'})}}}]}));
  afterEach(()=>{http.verify();vi.restoreAllMocks();});
  function editor(){
    http=TestBed.inject(HttpTestingController);
    const fixture=TestBed.createComponent(SpellRevision); const component=fixture.componentInstance;
    http.expectOne(base).flush({policies:[{className:'Wizard',policyIds:[3]}]});
    component.policyId=3; component.load(); http.expectOne(base+'/3/revision').flush(data); fixture.detectChanges();
    return component;
  }
  it('requires confirmation and reason before replacing a selection',()=>{
    const e=editor();e.save();http.expectNone(r=>r.method==='POST');
    e.confirmed=true;e.save();http.expectNone(r=>r.method==='POST');
  });
  it('sends the expected current identities and an explicit override flag',()=>{
    const e=editor();e.reason='Explicit ruling';e.overrideRules=true;e.targetPolicyId=5;e.confirmed=true;
    e.save();e.save();const req=http.expectOne(r=>r.method==='POST');
    expect(req.request.body).toEqual({targetPolicyId:5,spellIds:[7],expectedAcquisitionIds:[4],reason:'Explicit ruling',overrideRules:true,confirmed:true});
    req.flush({message:'Stale selection'},{status:409,statusText:'Conflict'});
    expect(e.reason).toBe('Explicit ruling');expect(e.selected().length).toBe(1);expect(e.error()).toBe('Stale selection');
  });
  it('invalidates confirmation after a change and avoids duplicate selection',()=>{
    const e=editor();e.confirmed=true;e.add({id:8,name:'Second'});e.add({id:8,name:'Second'});
    expect(e.confirmed).toBe(false);expect(e.selected().map(s=>s.id)).toEqual([7,8]);
    e.remove(7);expect(e.selected().map(s=>s.id)).toEqual([8]);
    vi.spyOn(window,'confirm').mockReturnValue(false);e.policyId=5;e.load();
    expect(e.policyId).toBe(3);http.expectNone(base+'/5/revision');expect(e.canLeavePreparation()).toBe(false);
  });
  it('keeps target policy after success and reloads the journal',()=>{
    const e=editor();e.reason='Correction';e.targetPolicyId=5;e.confirmed=true;e.save();
    http.expectOne(r=>r.method==='POST').flush({revisionId:2});
    http.expectOne(base+'/5/revision').flush({...data,history:[{id:2,reason:'Correction',override_rules:false,decided_at:'today',retired_count:1,created_count:1}]});
    expect(e.policyId).toBe(5);expect(e.options()?.history.length).toBe(1);
  });
});

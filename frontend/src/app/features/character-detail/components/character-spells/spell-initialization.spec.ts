import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { beforeEach, afterEach, describe, it, expect, vi } from 'vitest';
import { SpellInitialization } from './spell-initialization';
import { CharacterSpells } from './character-spells';
import { provideRouter } from '@angular/router';

describe('Initial spell choices', () => {
  let http: HttpTestingController;
  const url = '/api/characters/100/spell-acquisitions/initialization';
  const data = (remaining = 3) => ({characterId:100, classes:[{
    classId:1, className:'Fixture', classLevel:1, status:'policy-configured', policyIds:[7], policies:[{id:7,version:1,formatVersion:1}], restrictions:[],
    options:{policyId:7, learningStyle:'spellbook', status:'choices-to-complete', blocked:[], acquisitions:[], automatic:[], limitations:[],
      limits:{cantrips:3,known:0,bookAdditions:6,maxSpellLevel:1}, used:{cantrips:3-remaining,known:0,spellbook:0}, remaining:{cantrips:remaining,known:0,spellbook:6},
      available:[{id:11,name:'Fixture cantrip',level:0,school:'evocation'},{id:12,name:'Fixture book',level:1,school:'abjuration'}]}
  }]});
  beforeEach(() => {
    TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting(),provideRouter([])]});
    http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => http.verify());
  function mount(level = 1) {
    const f = TestBed.createComponent(SpellInitialization);
    f.componentRef.setInput('characterId',100); f.componentRef.setInput('totalLevel',level); f.detectChanges();
    return f;
  }
  it('shows separate policy quotas and candidates', () => {
    const f = mount(); http.expectOne(url).flush(data()); f.detectChanges();
    const quotas = Array.from(f.nativeElement.querySelectorAll('.category-tabs strong')) as HTMLElement[];
    expect(quotas.map(element => element.textContent)).toEqual(['0/3', '0/6']);
    expect(f.componentInstance.categories()[0].spells.map(s=>s.id)).toEqual([11]);
    expect(f.componentInstance.categories()[1].spells.map(s=>s.id)).toEqual([12]);
  });
  it('saves partial choices once and refreshes both preview and parent', () => {
    const f = mount(); http.expectOne(url).flush(data());
    const c = f.componentInstance; const saved = vi.fn(); c.saved.subscribe(saved);
    const category = c.categories()[0]; c.toggle(category.key,11); c.save(category); c.save(category);
    const request = http.expectOne(r=>r.method==='POST' && r.url===url);
    expect(request.request.body).toEqual({policyId:7,reason:'Sélection initiale',choices:[{spellId:11,ruleId:null}]});
    request.flush({acquisitionIds:[9],createdAcquisitionIds:[9],replayedAcquisitionIds:[]});
    http.expectOne(url).flush(data(2)); f.detectChanges();
    expect(saved).toHaveBeenCalledTimes(1); expect(c.categories()[0].remaining).toBe(2);
    expect(f.nativeElement.querySelector('.category-tabs strong').textContent).toBe('1/3');
    expect(c.selection()).toEqual({});
  });
  it('preserves selection and displays backend refusal', () => {
    const f = mount(); http.expectOne(url).flush(data()); const c = f.componentInstance;
    const category = c.categories()[0]; c.toggle(category.key,11); c.save(category);
    http.expectOne(url).flush({message:'Quota atteint.'},{status:409,statusText:'Conflict'}); f.detectChanges();
    expect(c.selection()[category.key]).toEqual([11]); expect(c.saving()).toBe(false);
    expect(f.nativeElement.querySelector('[role=alert]').textContent).toContain('Quota atteint.');
  });
  it('does not request or display initialization above level one', () => {
    const f = mount(2); http.expectNone(url); expect(f.nativeElement.querySelector('section')).toBeNull();
  });
  it('does not offer choices for an ambiguous policy', () => {
    const f = mount(); const preview = data(); preview.classes[0].status='policy-ambiguous';
    http.expectOne(url).flush(preview); f.detectChanges(); expect(f.nativeElement.querySelector('section')).toBeNull();
  });
  it('uses the advanced rule identity supplied by the server', () => {
    const f = mount(); const preview = data();
    const advanced = {...preview.classes[0],restrictions:[{ruleId:42,key:'restricted',parameters:{opportunities:[2],schools:['abjuration'],minimumSpellLevel:1,maxSpellLevels:[1]}}],
      options:{...preview.classes[0].options, formatVersion:2,choices:[{ruleId:42,key:'restricted',quota:'known',result:'known',remaining:2,available:[{id:12,name:'Fixture book',level:1,school:'abjuration'}]}]}};
    http.expectOne(url).flush({...preview,classes:[advanced]});
    const c=f.componentInstance; const category=c.categories()[0]; c.toggle(category.key,12); c.save(category);
    const request=http.expectOne(url); expect(request.request.body.choices).toEqual([{spellId:12,ruleId:42}]);
    request.flush({message:'Fixture refusal'},{status:409,statusText:'Conflict'});
  });
  it('blocks submission if refreshing after success fails', () => {
    const f=mount(); http.expectOne(url).flush(data()); const c=f.componentInstance;
    const category=c.categories()[0]; c.toggle(category.key,11); c.save(category);
    http.expectOne(url).flush({acquisitionIds:[1],createdAcquisitionIds:[1],replayedAcquisitionIds:[]});
    http.expectOne(url).flush({message:'Unavailable'},{status:503,statusText:'Unavailable'});
    expect(c.error()).toBe('Unavailable');
    c.toggle(category.key,11); c.save(category); http.expectNone(url);
  });
  it('links to dedicated initialization and keeps all management links', () => {
    const f=TestBed.createComponent(CharacterSpells);
    f.componentRef.setInput('character',{id:100,totalLevel:1,resources:[]}); f.componentRef.setInput('campaignId',1); f.detectChanges();
    const statusUrl='/api/characters/100/spell-acquisitions';
    http.expectOne(statusUrl).flush({policies:[]}); http.expectOne(url).flush(data()); f.detectChanges();
    http.expectOne('/api/characters/100/feature-spells').flush({known:[],pending:[]}); f.detectChanges();
    expect(f.nativeElement.querySelector('.initialization-banner a').getAttribute('href')).toBe('/campaigns/1/characters/100/spell-initialization');
    const links=Array.from(f.nativeElement.querySelectorAll('.spell-action-links a')) as HTMLAnchorElement[];
    expect(links.map(a=>a.getAttribute('href'))).toEqual(['/campaigns/1/characters/100/spellbook','/campaigns/1/characters/100/spell-revision','/campaigns/1/characters/100/spell-substitutions']);
    http.expectNone(url);
  });
});

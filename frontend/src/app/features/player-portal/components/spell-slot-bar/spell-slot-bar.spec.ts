import { TestBed } from '@angular/core/testing';
import { afterEach, describe, expect, it } from 'vitest';
import { SpellSlotBar, spellSlotGroups } from './spell-slot-bar';
import { characterProfileToCharacter, CharacterProfilePayload, CharacterSessionStatePayload } from '@core/mappers/character-api.mapper';

function mapped(values: CharacterSessionStatePayload['resources']) {
  const profile = {slug:'test',name:'Test',type:'player',totalLevel:1,classSummary:[],hitPoints:{maximumValue:10},
    resources:[{slug:'spell-slot-1',name:'I',maximum:3,rechargeType:'long-rest'},
      {slug:'spell-slot-2',name:'II',maximum:0,rechargeType:'long-rest'},
      {slug:'warlock-pact-slots',name:'Pacte',maximum:2,rechargeType:'short-rest',slotLevel:3},
      {slug:'other',name:'Autre',maximum:1,rechargeType:'long-rest'}],progressions:[],definition:{}} as unknown as CharacterProfilePayload;
  return characterProfileToCharacter(profile,{hitPoints:{current:10,temporary:0},hitDice:[],progressions:[],resources:values} as unknown as CharacterSessionStatePayload).resources;
}
afterEach(()=>TestBed.resetTestingModule());
describe('Spell slot bar',()=>{
  it('renders server totals, available and spent cells, and distinct pact slots without controls',()=>{
    const resources=mapped([{id:'spell-slot-1',currentValue:2},{id:'warlock-pact-slots',currentValue:0}]);
    const before=JSON.stringify(resources);
    const fixture=TestBed.createComponent(SpellSlotBar);
    fixture.componentRef.setInput('groups',spellSlotGroups(resources)); fixture.detectChanges();
    expect(fixture.nativeElement.querySelectorAll('.slot-group')).toHaveLength(2);
    expect(fixture.nativeElement.querySelectorAll('.cell')).toHaveLength(5);
    expect(fixture.nativeElement.querySelectorAll('.full')).toHaveLength(2);
    expect(fixture.nativeElement.querySelector('.slot-group').getAttribute('aria-label')).toContain('2 sur 3');
    expect(fixture.nativeElement.querySelector('.pact').getAttribute('aria-label')).toContain('niveau 3');
    expect(fixture.nativeElement.querySelector('.pact').getAttribute('aria-label')).toContain('repos court');
    expect(fixture.nativeElement.querySelector('[aria-label="Emplacement 3 : consommé"]')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('button,input')).toBeNull();
    const represented=new Set(spellSlotGroups(resources).map(g=>g.id));
    expect(resources.filter(r=>!represented.has(r.id)).map(r=>r.id)).toEqual(['spell-slot-2','other']);
    expect(JSON.stringify(resources)).toBe(before);
    fixture.componentRef.setInput('groups',spellSlotGroups(mapped([{id:'spell-slot-1',currentValue:1}]))); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.slot-group').getAttribute('aria-label')).toContain('1 sur 3');
    expect(fixture.nativeElement.querySelectorAll('.full')).toHaveLength(1);
  });
  it.each([[],[{id:'spell-slot-1',currentValue:-1}],[{id:'spell-slot-1',currentValue:4}],
    [{id:'spell-slot-1',currentValue:1.5}],[{id:'spell-slot-1',currentValue:1},{id:'spell-slot-1',currentValue:2}]].map(values=>({values})))('keeps unknown counters unknown: %j',({values})=>{
    const fixture=TestBed.createComponent(SpellSlotBar);
    fixture.componentRef.setInput('groups',spellSlotGroups(mapped(values))); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.slot-group').getAttribute('aria-label')).toContain('quantité inconnue sur 3');
    expect(fixture.nativeElement.querySelectorAll('.full')).toHaveLength(0);
    expect(fixture.nativeElement.querySelector('[aria-label="Emplacement 1 : état inconnu"]')).not.toBeNull();
  });
  it('does not collapse ambiguous duplicate pools or infer legacy pools',()=>{
    const resources=mapped([]);
    expect(spellSlotGroups([...resources,resources[0]]).some(g=>g.id==='spell-slot-1')).toBe(false);
    expect(spellSlotGroups(resources.map(({slotPool,...rest})=>rest))).toEqual([]);
  });
  it('uses a wrapping five-column flex layout for nine sorted standard levels',()=>{
    const base=mapped([{id:'spell-slot-1',currentValue:2}])[0];
    const groups=spellSlotGroups(Array.from({length:9},(_,i)=>({...base,id:`spell-slot-${9-i}`,slotPool:{level:9-i,counterKnown:true}})));
    const fixture=TestBed.createComponent(SpellSlotBar); fixture.componentRef.setInput('groups',groups); fixture.detectChanges();
    expect(Array.from(fixture.nativeElement.querySelectorAll('strong'),(el:any)=>el.textContent)).toEqual(['I','II','III','IV','V','VI','VII','VIII','IX']);
    const style=getComputedStyle(fixture.nativeElement.querySelector('.slot-groups'));
    expect(style.display).toBe('flex'); expect(style.flexWrap).toBe('wrap');
    expect(getComputedStyle(fixture.nativeElement.querySelector('.slot-group')).flexBasis).toContain('20%');
  });
});

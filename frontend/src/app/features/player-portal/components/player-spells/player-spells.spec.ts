import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { afterEach, describe, expect, it } from 'vitest';
import { PlayerSpells } from './player-spells';
import type { PlayerSpellView } from '@core/services/player-spell-api.service';

const base = '/api/public/characters/test/spells';
function view(): PlayerSpellView {
  return { revision: 3, editable: true, warnings: [],
    spells: [{ key:'book:1', policyId:7, spellId:1, name:'Bouclier', level:1, school:'abjuration', source:'Magicien', sourceType:'class',
      castingAbility:'intelligence', spellSaveDc:13, spellAttackBonus:5, known:false, inSpellbook:true, prepared:false, alwaysPrepared:false }],
    choices: [0,1].map(n => ({ key:`feature:${n}`, kind:'feature', grantId:n+10, policyId:null, ruleId:null,
      source:'Initié à la magie', label:n ? 'Sort octroyé' : 'Tour de magie', ability:'charisma', remaining:1,
      available:[{id:n+2,name:n ? 'Maléfice' : 'Main de mage',level:n}] })),
    preparations:[{policyId:7,source:'Magicien',ability:'intelligence',learningStyle:'spellbook',maximum:1,selected:[],available:[{id:1,name:'Bouclier',level:1}],blocked:false}],
  };
}
async function setup(data = view(), readonly = false) {
  TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting(),provideRouter([])]});
  const fixture = TestBed.createComponent(PlayerSpells);
  fixture.componentRef.setInput('spells', []);
  fixture.componentRef.setInput('accessToken', 'test');
  fixture.componentRef.setInput('readonly', readonly);
  fixture.detectChanges();
  const http = TestBed.inject(HttpTestingController);
  http.expectOne(base).flush(data);
  await fixture.whenStable();
  return {fixture,http,component:fixture.componentInstance};
}
afterEach(() => { TestBed.inject(HttpTestingController).verify(); TestBed.resetTestingModule(); });
describe('Player spell autonomy', () => {
  it('loads the public spell response and preserves independent grant choices', async () => {
    const {fixture,component,http} = await setup();
    const data = view();
    component.selections['feature:0'] = 2;
    component.choose(data.choices[0]);
    const request = http.expectOne(base+'/choose');
    expect(request.request.body).toEqual({revision:3,grantId:10,spellId:2});
    request.flush({...data,revision:4,choices:[data.choices[1]]});
    await fixture.whenStable();
    expect(component.view()?.choices.map(choice => choice.grantId)).toEqual([11]);
    expect(fixture.nativeElement.textContent).toContain('Sort appris.');
  });
  it('uses the class acquisition endpoint without an override or historical level', async () => {
    const data = view();
    data.choices[0] = {...data.choices[0],kind:'class',policyId:7,grantId:null,ruleId:9};
    const {component,http} = await setup(data);
    component.selections['feature:0'] = 2;
    component.choose(data.choices[0]);
    const request = http.expectOne(base+'/learn');
    expect(request.request.body).toEqual({revision:3,policyId:7,ruleId:9,spellId:2});
    request.flush({...data,revision:4});
  });
  it('immediately reflects preparation and unpreparation without duplicating book spells', async () => {
    const {fixture,component,http} = await setup();
    component.toggle(view().preparations[0],1);
    const request = http.expectOne(base+'/prepare');
    expect(request.request.body).toEqual({revision:3,policyId:7,spellIds:[1]});
    const data = view(); data.revision=4; data.spells[0].prepared=true; data.preparations[0].selected=[1];
    request.flush(data); await fixture.whenStable();
    expect(component.displayed()).toHaveLength(1);
    expect(component.displayed()[0].inSpellbook).toBe(true);
    expect(component.displayed()[0].prepared).toBe(true);
    component.toggle(data.preparations[0],1);
    const remove = http.expectOne(base+'/prepare');
    expect(remove.request.body.spellIds).toEqual([]);
    remove.flush({...view(),revision:5}); await fixture.whenStable();
    expect(component.displayed()[0].prepared).toBe(false);
  });
  it('preserves the displayed state on rejection and reloads a stale revision', async () => {
    const {fixture,component,http} = await setup();
    component.toggle(view().preparations[0],1);
    http.expectOne(base+'/prepare').flush({message:'État modifié'}, {status:409,statusText:'Conflict'});
    http.expectOne(base).flush({...view(),revision:8}); await fixture.whenStable();
    expect(component.view()?.revision).toBe(8);
    expect(component.displayed()[0].prepared).toBe(false);
    expect(fixture.nativeElement.querySelector('[role=alert]').textContent).toContain('État modifié');
  });
  it('does not send mutations in read-only mode', async () => {
    const {component,http} = await setup(view(),true);
    component.selections['feature:0']=2;
    component.choose(view().choices[0]); component.toggle(view().preparations[0],1);
    http.expectNone(request => request.method==='POST');
  });
  it('renders the empty character state', async () => {
    const {fixture} = await setup({...view(),spells:[],choices:[],preparations:[]});
    expect(fixture.nativeElement.textContent).toContain('Aucun sort enregistré');
    expect(fixture.nativeElement.querySelector('fieldset')).toBeNull();
  });
  it('opens spell details from the quickbar without sending a casting request', async () => {
    const data = view();
    data.spells.push({...data.spells[0], key:'feature:12',spellId:9,name:'Décharge occulte',known:true,inSpellbook:false,source:'Initié à la magie',
      details:{description:'Description du sort sélectionné.',higherLevels:null,castingTime:'1 action',range:'36 m',duration:'Instantanée',
        verbal:true,somatic:true,material:false,materialDescription:null,concentration:false,ritual:false}});
    const {fixture,http} = await setup(data);
    const buttons = fixture.nativeElement.querySelectorAll('.quick-spell');
    expect(buttons.length).toBe(2);
    buttons[1].click(); await fixture.whenStable();
    expect(buttons[1].getAttribute('aria-pressed')).toBe('true');
    expect(fixture.nativeElement.querySelector('.spell-details').textContent).toContain('Description du sort sélectionné.');
    expect(fixture.nativeElement.querySelector('.spell-modifiers').children.length).toBe(0);
    http.expectNone(request => request.method === 'POST');
  });
  it('keeps prepared spells on the portal and replaces the book list with a link', async () => {
    const data = view(); data.spells[0].prepared=true;
    const {fixture} = await setup(data);
    expect(fixture.nativeElement.querySelector('[aria-labelledby=spells-known]').querySelectorAll('.spell-row').length).toBe(0);
    expect(fixture.nativeElement.querySelector('[aria-labelledby=spells-prepared]').querySelectorAll('.spell-row').length).toBe(1);
    expect(fixture.nativeElement.querySelector('[aria-labelledby=spells-spellbook]')).toBeNull();
    expect(fixture.nativeElement.querySelector('a[aria-label="Ouvrir le grimoire"]')).not.toBeNull();
  });
  it('separates compact casting facts from description and components', async () => {
    const data = view();
    data.spells[0].details = {description:'Texte détaillé',higherLevels:null,castingTime:'1 réaction',range:'Personnelle',duration:'1 round',
      verbal:true,somatic:true,material:true,materialDescription:'Objet témoin',concentration:false,ritual:false};
    const {fixture} = await setup(data);
    const summary = fixture.nativeElement.querySelector('.spell-summary');
    for (const text of ['Bouclier','Abjuration','Niveau 1','Magicien','Intelligence','13','+5','1 réaction','Personnelle','1 round']) expect(summary.textContent).toContain(text);
    expect(summary.textContent).not.toContain('Texte détaillé');
    const body = fixture.nativeElement.querySelector('.spell-text');
    expect(body.textContent).toContain('Texte détaillé');
    expect(body.textContent).toContain('Objet témoin');
  });
  it('reloads the preparation controls after an external revision update', async () => {
    const {fixture,http,component} = await setup();
    fixture.componentRef.setInput('revision',4); fixture.detectChanges();
    http.expectOne(base).flush({...view(),revision:4}); await fixture.whenStable();
    expect(component.view()?.revision).toBe(4);
  });
});

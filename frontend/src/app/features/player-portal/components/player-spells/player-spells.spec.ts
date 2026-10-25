import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { afterEach, describe, expect, it } from 'vitest';
import { PlayerSpells } from './player-spells';
import type { PlayerSpell } from '@core/services/character-session-state-api.service';
import type { PlayerSpellView } from '@core/services/player-spell-api.service';

function available(spell: PlayerSpell): void {
  spell.castingAvailability = {sourceKey:spell.key!,policyId:spell.policyId ?? null,status:'available',reason:null,
    methods:[{kind:'standard-slot',castingLevel:1,poolSlug:'spell-slot-1',cost:1,status:'available',reason:null}]};
}
const base = '/api/public/characters/test/spells';
function view(): PlayerSpellView {
  return { revision: 3, editable: true, spellPreparationAllowed: true, warnings: [],
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
  it('sorts only the quickbar by real level then French name without changing source entries', async () => {
    const data = view();
    const spell = data.spells[0];
    data.spells = [
      {...spell, key:'level2-z', name:'Zone de vérité', level:2, prepared:true},
      {...spell, key:'level1-z', name:'Soins', level:1, prepared:true},
      {...spell, key:'level0-z', name:'Lumière', level:0},
      {...spell, key:'level2-a', name:'Aide', level:2, prepared:true},
      {...spell, key:'level1-a', name:'Bénédiction', level:1, prepared:true},
      {...spell, key:'level0-a', name:'Éclair de feu', level:0},
    ];
    const original = structuredClone(data.spells);
    const {fixture,component} = await setup(data);
    expect(Array.from(fixture.nativeElement.querySelectorAll('.quick-name') as NodeListOf<HTMLElement>)
      .map(node => node.textContent!.trim()))
      .toEqual(['Éclair de feu','Lumière','Bénédiction','Soins','Aide','Zone de vérité']);
    expect(fixture.nativeElement.querySelectorAll('.quick-spell')).toHaveLength(original.length);
    expect(component.displayed()).toEqual(original);
    expect(component.view()!.spells).toEqual(original);
    expect(data.spells).toEqual(original);
    expect(component.sections().find(section => section.key === 'prepared')!.spells.map(spell => spell.name))
      .toEqual(['Zone de vérité','Soins','Aide','Bénédiction']);
  });
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
  it('replaces immediate preparation controls with navigation', async () => {
    const {fixture,http} = await setup();
    expect(fixture.nativeElement.querySelector('a.preparation-link').textContent).toContain('Préparer mes sorts');
    expect(fixture.nativeElement.querySelector('a.preparation-link').getAttribute('href')).toContain('prepare-spells');
    expect(fixture.nativeElement.querySelector('input[type=checkbox]')).toBeNull();
    http.expectNone(request => request.method === 'POST');
  });
  it('preserves the displayed state on rejection and reloads a stale revision', async () => {
    const {fixture,component,http} = await setup();
    component.selections['feature:0'] = 2;
    component.choose(view().choices[0]);
    http.expectOne(base+'/choose').flush({message:'État modifié'}, {status:409,statusText:'Conflict'});
    http.expectOne(base).flush({...view(),revision:8}); await fixture.whenStable();
    expect(component.view()?.revision).toBe(8);
    expect(component.displayed()[0].prepared).toBe(false);
    expect(fixture.nativeElement.querySelector('[role=alert]').textContent).toContain('État modifié');
  });
  it('does not send mutations in read-only mode', async () => {
    const {component,http} = await setup(view(),true);
    component.selections['feature:0']=2;
    component.choose(view().choices[0]);
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
    available(data.spells[1]); const {fixture,http} = await setup(data);
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
    data.spells[0].prepared = true; available(data.spells[0]);
    data.spells[0].details = {description:'Texte détaillé',higherLevels:null,castingTime:'1 réaction',range:'Personnelle',duration:'1 round',
      verbal:true,somatic:true,material:true,materialDescription:'Objet témoin',concentration:false,ritual:false};
    const {fixture} = await setup(data);
    fixture.nativeElement.querySelector('.quick-spell').click(); await fixture.whenStable();
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
  it.each(['.quick-spell', '.spell-row'])('opens details only after clicking %s and retains them through unpreparation and refresh', async selector => {
    const data = view();
    data.spells[0].prepared = true; available(data.spells[0]);
    data.preparations[0].selected = [1];
    const {fixture,http,component} = await setup(data);
    expect(fixture.nativeElement.querySelector('.spell-details')).toBeNull();
    fixture.nativeElement.querySelector(selector).click(); await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('.spell-details').textContent).toContain('Bouclier');
    component.reload();
    http.expectOne(base).flush({...view(),revision:4}); await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('.quick-spell').disabled).toBe(true);
    expect(fixture.nativeElement.querySelector('.spell-details').textContent).toContain('Bouclier');
    expect(component.selectedSpell()?.prepared).toBe(false);
    component.reload();
    expect(fixture.nativeElement.querySelector('.spell-details').textContent).toContain('Bouclier');
    http.expectOne(base).flush({...view(),revision:5,spells:[]}); await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('.spell-details').textContent).toContain('Bouclier');
    expect(component.selectedSpell()?.spellId).toBe(1);
  });
  it('keeps all directory entries in the quickbar regardless of preparation', async () => {
    const data = view();
    const book = data.spells[0];
    data.spells = [book,
      {...book,key:'cantrip',spellId:2,name:'Tour de magie',level:0,known:true,inSpellbook:false,prepared:null},
      {...book,key:'prepared',spellId:3,name:'Sort préparé',prepared:true},
      {...book,key:'always',spellId:4,name:'Toujours préparé',sourceType:'subclass',inSpellbook:false,prepared:true,alwaysPrepared:true},
      {...book,key:'feat',spellId:5,name:'Sort de don',sourceType:'feature',known:true,inSpellbook:false,prepared:null},
      {...book,key:'known',spellId:6,name:'Sort connu',known:true,inSpellbook:false,prepared:null},
      {...book,key:'unavailable',spellId:7,name:'Sort non disponible',inSpellbook:false},
    ];
    const {fixture,component,http} = await setup(data);
    const names = () => Array.from(fixture.nativeElement.querySelectorAll('.quick-name') as NodeListOf<HTMLElement>).map(node => node.textContent!.trim());
    expect(names()).toEqual(['Tour de magie','Bouclier','Sort connu','Sort de don','Sort non disponible','Sort préparé','Toujours préparé']);
    expect(component.displayed()).toEqual(data.spells);
    expect(component.sections().find(section => section.key === 'known')!.spells).toHaveLength(3);
    const consultation = fixture.nativeElement.querySelector('[aria-labelledby=spells-other] .spell-row') as HTMLButtonElement;
    consultation.click(); await fixture.whenStable();
    expect(component.selectedSpell()?.key).toBe(book.key);
    http.expectNone(request => request.method !== 'GET');
    component.reload();
    const prepared = {...data,revision:4,spells:data.spells.map(spell => spell.key === book.key ? {...spell,prepared:true} : spell)};
    http.expectOne(base).flush(prepared); await fixture.whenStable();
    expect(names()).toContain('Bouclier');
    component.reload();
    http.expectOne(base).flush({...data,revision:5}); await fixture.whenStable();
    expect(names()).toContain('Bouclier');
    fixture.componentRef.setInput('mode','spellbook'); await fixture.whenStable();
    expect(component.sections().flatMap(section => section.spells).map(spell => spell.name)).toEqual(['Bouclier','Sort préparé']);
  });
});

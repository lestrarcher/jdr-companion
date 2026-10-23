import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, describe, expect, it } from 'vitest';
import { routes } from '../../app.routes';
import { PlayerSpellbook } from './player-spellbook';
import type { PlayerSpell } from '@core/services/character-session-state-api.service';

const portal = '/campaigns/2/sessions/3/player/test';
function spell(id: number, level: number, inSpellbook = true): PlayerSpell {
  return {key:`spell:${id}`,spellId:id,name:`Sort ${id}`,level,school:'abjuration',source:'Magicien',sourceType:'class',
    castingAbility:'intelligence',spellSaveDc:13,spellAttackBonus:5,known:!inSpellbook,inSpellbook,prepared:id===1};
}
async function setup(spells: PlayerSpell[]) {
  TestBed.configureTestingModule({providers:[provideRouter(routes),provideHttpClient(),provideHttpClientTesting()]});
  const harness = await RouterTestingHarness.create();
  await harness.navigateByUrl(portal+'/spellbook',PlayerSpellbook);
  const http = TestBed.inject(HttpTestingController);
  http.expectOne('/api/public/characters/test/spells').flush({revision:1,editable:true,spells,choices:[],preparations:[],warnings:[]});
  harness.detectChanges();
  return {harness,http,element:harness.routeNativeElement!};
}
afterEach(() => { TestBed.inject(HttpTestingController).verify(); TestBed.resetTestingModule(); });
describe('Player spellbook page', () => {
  it('uses the public route and groups only book spells by increasing level', async () => {
    const {element,http} = await setup([spell(3,3),spell(1,1),spell(2,1),spell(4,0,false)]);
    const groups = element.querySelectorAll('.spell-section');
    expect(groups.length).toBe(2);
    expect(groups[0].querySelector('h3')!.textContent).toContain('Niveau 1');
    expect(groups[0].querySelectorAll('.spell-row').length).toBe(2);
    expect(groups[1].querySelector('h3')!.textContent).toContain('Niveau 3');
    expect(element.textContent).not.toContain('Sort 4');
    expect(element.querySelector('.back-link')!.getAttribute('href')).toBe(portal+'?tab=spells');
    expect(element.querySelector('.spellbook-copy-slot')!.children.length).toBe(0);
    expect(element.querySelector('fieldset')).toBeNull();
    http.expectNone(request => request.method !== 'GET');
  });
  it('handles an empty book without presenting a copy action', async () => {
    const {element} = await setup([]);
    expect(element.textContent).toContain('Aucun sort inscrit dans le grimoire.');
    expect(element.querySelector('.spell-section')).toBeNull();
    expect(element.textContent).not.toContain('Recopier un sort');
  });
});

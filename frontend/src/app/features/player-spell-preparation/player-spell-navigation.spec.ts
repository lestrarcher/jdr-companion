import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, describe, expect, it } from 'vitest';
import { PlayerSpells } from '../player-portal/components/player-spells/player-spells';
import { PlayerSpellPreparationPage } from './player-spell-preparation';
import { preparationGuard } from '@core/guards/preparation-guard';
import type { PlayerSpellView } from '@core/services/player-spell-api.service';

const portal = '/player/test';
const base = '/api/public/characters/test/spells';
@Component({ imports: [PlayerSpells], template: `
  <button class="other-tab" (click)="spells.set(false)">Personnage</button>
  @if (spells()) {
    <app-player-spells [spells]="[]" accessToken="test" [readonly]="false"
      [revision]="revision()" [prioritizePreparation]="true" />
  } @else { <p>Personnage</p> }
` })
class PortalStub {
  readonly revision = signal(0);
  readonly spells = signal(true);
}
function view(): PlayerSpellView {
  return { revision: 7, editable: true, spellPreparationAllowed: true, choices: [], warnings: [], spells: [],
    preparations: [{policyId: 10, source: 'Clerc', ability: 'wisdom', learningStyle: 'prepared',
      maximum: 1, selected: [], available: [], blocked: false}] };
}
async function setup() {
  TestBed.configureTestingModule({providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([
    {path: 'player/:accessToken/prepare-spells', component: PlayerSpellPreparationPage, canDeactivate: [preparationGuard]},
    {path: 'player/:accessToken', component: PortalStub},
  ])]});
  const harness = await RouterTestingHarness.create();
  const component = await harness.navigateByUrl(portal+'?tab=spells', PortalStub);
  return {harness, component, http: TestBed.inject(HttpTestingController), router: TestBed.inject(Router)};
}
afterEach(() => { TestBed.inject(HttpTestingController).verify(); TestBed.resetTestingModule(); });
describe('Spell preparation navigation', () => {
  it.each(['locked', 'known'] as const)('keeps %s casters on the portal without a preparation link', async mode => {
    const {harness,http,router} = await setup();
    const data = view();
    if (mode === 'locked') data.spellPreparationAllowed = false;
    else data.preparations = [];
    http.expectOne(base).flush(data); await harness.fixture.whenStable();
    expect(router.url).toBe(portal+'?tab=spells');
    expect(harness.routeNativeElement!.querySelector('.preparation-link')).toBeNull();
  });
  it('prioritizes preparation and allows an explicit return without a loop or loss of other tabs', async () => {
    const {harness,http,router} = await setup();
    http.expectOne(base).flush(view()); await harness.fixture.whenStable();
    expect(router.url).toBe(portal+'/prepare-spells');
    http.expectOne(base).flush(view()); harness.detectChanges();
    harness.routeNativeElement!.querySelector<HTMLAnchorElement>('.back-link')!.click();
    await harness.fixture.whenStable();
    http.expectOne(base).flush(view()); await harness.fixture.whenStable();
    expect(router.url).toBe(portal+'?tab=spells');
    expect(harness.routeNativeElement!.querySelector('.preparation-link')).not.toBeNull();
    http.expectNone(request => true);
    harness.routeNativeElement!.querySelector<HTMLButtonElement>('.other-tab')!.click(); harness.detectChanges();
    expect(harness.routeNativeElement!.querySelector('app-player-spells')).toBeNull();
    http.expectNone(request => request.method !== 'GET');
  });
  it('returns after one confirmation and redirects again when the approved long rest reopens preparation', async () => {
    const {harness,http,router} = await setup();
    http.expectOne(base).flush(view()); await harness.fixture.whenStable();
    http.expectOne(base).flush(view()); harness.detectChanges();
    const button = harness.routeNativeElement!.querySelector<HTMLButtonElement>('.confirm')!;
    button.click(); button.click();
    const confirmed = {...view(), revision: 8, spellPreparationAllowed: false};
    http.expectOne(base+'/confirm-preparation').flush(confirmed);
    await harness.fixture.whenStable();
    http.expectOne(base).flush(confirmed); await harness.fixture.whenStable();
    expect(router.url).toBe(portal+'?tab=spells');
    expect(harness.routeNativeElement!.querySelector('[role="status"]')).toBeNull();
    expect(harness.routeNativeElement!.textContent).not.toContain('Votre préparation est confirmée');
    expect(harness.routeNativeElement!.querySelector('.preparation-link')).toBeNull();
    http.expectNone(request => request.method !== 'GET');
    const returned = harness.routeDebugElement!.componentInstance as PortalStub;
    returned.revision.set(9); harness.detectChanges();
    http.expectOne(base).flush({...view(), revision: 9}); await harness.fixture.whenStable();
    expect(router.url).toBe(portal+'/prepare-spells');
    http.expectOne(base).flush({...view(), revision: 9}); harness.detectChanges();
    harness.routeNativeElement!.querySelector<HTMLAnchorElement>('.back-link')!.click();
    await harness.fixture.whenStable();
    http.expectOne(base).flush({...view(), revision: 9}); await harness.fixture.whenStable();
    expect(harness.routeNativeElement!.querySelector('.preparation-link')).not.toBeNull();
  });
});

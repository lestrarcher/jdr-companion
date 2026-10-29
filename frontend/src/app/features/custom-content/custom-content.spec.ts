import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Component } from '@angular/core';
import { By } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { routes } from '../../app.routes';
import { AuthService } from '@core/services/auth.service';
import { CustomContent } from './custom-content';
import { DefinitionManager } from './definition-manager/definition-manager';
import { MjLayout } from '../mj-layout/mj-layout';

describe('Personal custom catalogue', () => {
  let http: HttpTestingController;
  let fixture: ComponentFixture<CustomContent>;
  const resource = { id: 1, name: 'Souffle', slug: 'private-slug', description: 'Réserve personnelle', rechargeType: 'long-rest', maximumType: 'fixed', baseMaximum: 3, multiplier: 1, minimumMaximum: 0, scalingAbility: null };
  const feature = { id: 2, name: 'Vision', slug: 'private-feature', description: 'Fiction', resourceDefinition: null };
  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter(routes),
      { provide: AuthService, useValue: { isAuthenticated: () => true } }] });
    http = TestBed.inject(HttpTestingController);
    vi.spyOn(window, 'confirm').mockReturnValue(true);
  });
  afterEach(() => { http.verify(); vi.restoreAllMocks(); });
  const root = (): HTMLElement => fixture.nativeElement;
  async function settle() { fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges(); }
  function flushPending(overrides: Record<string, object> = {}) {
    for (const r of http.match(() => true)) {
      expect(r.request.method).toBe('GET');
      const path = r.request.url.split('/').at(-1)!;
      r.flush(overrides[path] ?? { features: [], resources: [], rules: [], classes: [], subclasses: [], races: [], feats: [], progressions: [] });
    }
    fixture.detectChanges();
  }
  async function start() { fixture = TestBed.createComponent(CustomContent); fixture.detectChanges(); flushPending(); await settle(); }
  async function select(label: string) {
    const b = Array.from(root().querySelectorAll<HTMLButtonElement>('.categories button')).find(b => b.textContent?.trim() === label)!;
    expect(b.disabled).toBe(false); b.click(); await settle();
  }
  const manager = () => fixture.debugElement.query(By.directive(DefinitionManager)).componentInstance as DefinitionManager;

  it('exposes all current categories and blocks navigation while the initial editor loads', async () => {
    fixture = TestBed.createComponent(CustomContent); fixture.detectChanges();
    const labels = Array.from(root().querySelectorAll<HTMLButtonElement>('.categories button'));
    expect(labels.map(b => b.textContent?.trim())).toEqual(['Classes', 'Sous-classes', 'Races', 'Dons', 'Progressions', 'Historiques', 'Sorts', 'Capacités', 'Ressources', 'Attributions de capacités', 'Règles de ressources']);
    expect(labels.every(b => b.disabled)).toBe(true);
    flushPending(); await settle(); expect(labels.every(b => !b.disabled)).toBe(true);
  });
  it('opens existing personal features without exposing their immutable slug', async () => {
    await start(); manager().load(); flushPending({ features: { features: [feature] } }); await settle();
    root().querySelector<HTMLButtonElement>('.select-item')!.click(); await settle();
    expect(manager().name()).toBe('Vision'); expect(manager().description()).toBe('Fiction');
    expect(root().querySelector('[name="slug"]')).toBeNull();
  });
  it('reloads the reused editor when switching between features and attribution types', async () => {
    await start(); await select('Attributions de capacités');
    const rule = { id: 4, featureDefinition: feature, source: { id: 7, type: 'progression', name: 'Corruption' }, unlockLevel: null, progressionThreshold: 0 };
    flushPending({ 'feature-rules': { rules: [rule] } }); await settle();
    expect(root().textContent).toContain('Vision → Corruption');
    manager().levelFilter.set('0'); expect(manager().filtered()).toHaveLength(1);
    await select('Règles de ressources');
    flushPending({ 'resource-rules': { rules: [{ id: 5, resourceDefinition: resource, source: { id: 8, type: 'class', name: 'Classe' }, unlockLevel: 2, maximumBonus: 0 }] } }); await settle();
    expect(root().textContent).toContain('Souffle → Classe'); expect(root().textContent).not.toContain('Vision → Corruption');
  });
  it('renders resource formulas and preserves a zero maximum', async () => {
    await start(); await select('Ressources');
    flushPending({ resources: { resources: [resource, { ...resource, id: 2, baseMaximum: 0, maximumType: 'proficiency-bonus', multiplier: 2, rechargeType: 'short-rest' }, { ...resource, id: 3, maximumType: 'ability-modifier', scalingAbility: 'wisdom', minimumMaximum: 1, rechargeType: 'none' }] } }); await settle();
    expect(root().textContent).toContain('Maximum de base : 3'); expect(root().textContent).toContain('0 + 2 × bonus de maîtrise');
    expect(root().textContent).toContain('3 + 1 × modificateur de Sagesse · minimum 1');
    expect(root().textContent).toContain('Recharge : repos court'); expect(root().textContent).toContain('Aucune recharge automatique');
  });
  it('caches the parent resource collection while separately reloading editor dependencies', async () => {
    await start(); await select('Ressources'); flushPending({ resources: { resources: [resource] } }); await settle();
    await select('Capacités'); flushPending(); await settle(); await select('Ressources');
    http.expectNone('/api/reference/custom/resources'); expect(root().textContent).toContain('Souffle');
  });
  it('retains a draft after a refused mutation and sends no ownership fields', async () => {
    await start(); const m = manager(); m.open(null); m.name.set('Brouillon'); m.save();
    const r = http.expectOne('/api/reference/custom/features'); expect(r.request.method).toBe('POST');
    expect(r.request.body).not.toHaveProperty('owner'); expect(r.request.body).not.toHaveProperty('origin');
    r.flush({}, { status: 409, statusText: 'Conflict' }); await settle();
    expect(m.name()).toBe('Brouillon'); expect(m.editing()).toBe(true); expect(root().textContent).toContain('conflit');
  });
  it('protects an unsaved feature when changing categories', async () => {
    await start(); manager().open(null); manager().name.set('Brouillon'); vi.mocked(window.confirm).mockReturnValue(false);
    await select('Ressources'); expect(manager().name()).toBe('Brouillon'); http.expectNone('/api/reference/custom/resources');
  });
  it('isolates a resource load error and retries without mutating data', async () => {
    await start(); await select('Ressources');
    http.expectOne('/api/reference/custom/resources').flush({}, { status: 500, statusText: 'Error' }); await settle();
    expect(root().textContent).toContain('Impossible de charger'); root().querySelector<HTMLButtonElement>('.collection-state button')!.click();
    flushPending(); await settle(); expect(root().querySelector('[role="alert"]')).toBeNull();
  });
  it('cancels all pending requests when the catalogue and its editor are destroyed', () => {
    fixture = TestBed.createComponent(CustomContent); fixture.detectChanges();
    const requests = http.match(() => true); expect(requests.length).toBeGreaterThan(0);
    fixture.destroy(); expect(requests.every(r => r.cancelled)).toBe(true);
  });
  it('opens the guarded route without a campaign context', async () => {
    const harness = await RouterTestingHarness.create('/custom-content');
    for (const r of http.match(() => true)) { expect(r.request.url).toMatch(/^\/api\/reference\/custom\//); r.flush({ features: [], resources: [], classes: [], hitDice: [], spellcastingProgressions: [] }); }
    harness.detectChanges();
    expect(harness.routeNativeElement!.querySelector('h1')?.textContent).toBe('Référentiel des personnages');
    expect(harness.routeNativeElement!.querySelector('.breadcrumbs')?.textContent).not.toContain('Campagnes');
    expect(routes.find(r => r.path === '')?.canActivate).toHaveLength(1); http.expectNone('/api/campaigns');
  });
});
@Component({ template: '<h1>Campagne de test</h1>' })
class CampaignPlaceholder {}

describe('Custom catalogue navigation context', () => {
  it('cancels a pending campaign breadcrumb when entering the personal library', async () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(),
      provideRouter([{ path: '', component: MjLayout, children: [
        { path: 'campaigns/:campaignId', component: CampaignPlaceholder },
        { path: 'custom-content', component: CustomContent },
      ] }]),
    ] });
    const http = TestBed.inject(HttpTestingController);
    const harness = await RouterTestingHarness.create('/campaigns/1');
    const campaignRequest = http.expectOne('/api/campaigns');
    await harness.navigateByUrl('/custom-content');
    expect(campaignRequest.cancelled).toBe(true);
    for (const r of http.match(() => true)) r.flush({ features: [], resources: [] });
    harness.detectChanges();
    expect(harness.routeNativeElement!.querySelector('.breadcrumbs')?.textContent).toContain('Référentiel D&D');
    expect(harness.routeNativeElement!.querySelector('.breadcrumbs')?.getAttribute('aria-busy')).toBe('false');
    expect(harness.routeNativeElement!.querySelector('.primary-navigation')?.textContent).not.toContain('Sessions');
    http.verify();
  });
});

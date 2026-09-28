import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Component } from '@angular/core';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { routes } from '../../app.routes';
import { AuthService } from '@core/services/auth.service';
import { CustomResource } from '@core/models/custom-content.model';
import { CustomContent } from './custom-content';
import { MjLayout } from '../mj-layout/mj-layout';

describe('Personal custom catalogue', () => {
  let http: HttpTestingController;
  let fixture: ComponentFixture<CustomContent>;
  const reference = { id: 1, name: 'Guerrier', slug: 'hidden-source-slug', origin: 'OFFICIAL' as const };
  const resource: CustomResource = {
    id: 1, name: 'Souffle', slug: 'custom-hidden-slug', description: 'Une réserve personnelle.',
    rechargeType: 'long-rest', maximumType: 'fixed', baseMaximum: 3, multiplier: 1,
    minimumMaximum: 0, scalingAbility: null,
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter(routes),
        { provide: AuthService, useValue: { isAuthenticated: () => true } }],
    });
    http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => http.verify());
  function start() { fixture = TestBed.createComponent(CustomContent); fixture.detectChanges(); }
  function root(): HTMLElement { return fixture.nativeElement; }
  function flush(path: string, response: object) {
    const request = http.expectOne(`/api/reference/custom/${path}`);
    expect(request.request.method).toBe('GET');
    request.flush(response);
    fixture.detectChanges();
  }
  function select(label: string) {
    const button = Array.from(root().querySelectorAll<HTMLButtonElement>('.categories button'))
      .find(button => button.textContent?.trim() === label);
    expect(button).toBeDefined(); button!.click(); fixture.detectChanges();
  }
  function initialEmpty() { start(); flush('features', { features: [] }); }

  it('loads only the initial category, shows loading before empty, and exposes four real buttons', () => {
    start();
    expect(root().textContent).toContain('Chargement');
    expect(root().textContent).not.toContain('Aucune');
    expect(root().querySelector('section')?.getAttribute('aria-busy')).toBe('true');
    expect(Array.from(root().querySelectorAll('.categories button')).map(button => button.textContent?.trim()))
      .toEqual(['Capacités', 'Ressources', 'Attributions de capacités', 'Règles de ressources']);
    expect(root().querySelector('[aria-pressed="true"]')?.textContent).toContain('Capacités');
    http.expectNone('/api/reference/custom/resources');
    flush('features', { features: [] });
    expect(root().textContent).toContain('Aucune capacité personnalisée.');
    expect(root().querySelector('section')?.getAttribute('aria-busy')).toBe('false');
  });

  it('renders descriptive and resource-linked features without exposing slugs or empty relation labels', () => {
    start();
    flush('features', { features: [
      { id: 1, name: 'Vision', description: 'Voir dans le noir.', slug: 'hidden-feature', resourceDefinition: null },
      { id: 2, name: 'Souffle', description: null, slug: 'hidden-feature-2', resourceDefinition: { ...reference, name: 'Charges' } },
    ] });
    const cards = root().querySelectorAll('article');
    expect(cards).toHaveLength(2);
    expect(cards[0].textContent).toContain('Voir dans le noir.');
    expect(cards[0].textContent).not.toContain('Ressource liée');
    expect(cards[1].textContent).toContain('Ressource liée : Charges');
    expect(root().textContent).not.toMatch(/hidden-|OFFICIAL|CUSTOM/);
  });

  it('renders fixed, proficiency and ability resource mechanics and all recharge labels', () => {
    initialEmpty(); select('Ressources');
    flush('resources', { resources: [resource,
      { ...resource, id: 2, maximumType: 'proficiency-bonus', baseMaximum: 0, multiplier: 2, rechargeType: 'short-rest' },
      { ...resource, id: 3, maximumType: 'ability-modifier', scalingAbility: 'wisdom', minimumMaximum: 1, rechargeType: 'none' },
    ] });
    const text = root().textContent;
    expect(text).toContain('Une réserve personnelle.');
    expect(text).toContain('Maximum de base : 3');
    expect(text).toContain('0 + 2 × bonus de maîtrise');
    expect(text).toContain('3 + 1 × modificateur de Sagesse · minimum 1');
    expect(text).toContain('Recharge : repos court');
    expect(text).toContain('Recharge : repos long');
    expect(text).toContain('Aucune recharge automatique');
    expect(text).not.toContain('custom-hidden');
  });

  it('renders feature assignments using names and distinguishes a zero threshold from a level', () => {
    initialEmpty(); select('Attributions de capacités');
    flush('feature-rules', { rules: [
      { id: 1, featureDefinition: { ...reference, name: 'Fougue' }, source: { ...reference, type: 'class' }, unlockLevel: 2, progressionThreshold: null },
      { id: 2, featureDefinition: { ...reference, name: 'Vignes' }, source: { ...reference, type: 'progression', name: 'Corruption' }, unlockLevel: null, progressionThreshold: 0 },
    ] });
    const cards = root().querySelectorAll('article');
    expect(cards[0].textContent).toContain('Fougue → Guerrier');
    expect(cards[0].textContent).toContain('Niveau 2');
    expect(cards[1].textContent).toContain('Vignes → Corruption');
    expect(cards[1].textContent).toContain('Seuil 0');
    expect(cards[1].textContent).not.toContain('Niveau');
  });

  it('renders resource bonuses and zero-bonus grants without a +0 label', () => {
    initialEmpty(); select('Règles de ressources');
    flush('resource-rules', { rules: [1, 0].map((bonus, index) => ({
      id: index, resourceDefinition: { ...reference, name: 'Points de ki' },
      source: { ...reference, name: 'Moine', type: 'class' }, unlockLevel: 5, maximumBonus: bonus,
    })) });
    expect(root().textContent).toContain('Points de ki → Moine');
    expect(root().textContent).toContain('Niveau 5');
    expect(root().textContent).toContain('+1 au maximum');
    expect(root().textContent).toContain('Attribue la ressource');
    expect(root().textContent).not.toContain('+0');
  });

  it.each([
    ['Ressources', 'resources', 'resources', 'Aucune ressource personnalisée.'],
    ['Attributions de capacités', 'feature-rules', 'rules', 'Aucune attribution de capacité personnalisée.'],
    ['Règles de ressources', 'resource-rules', 'rules', 'Aucune règle de ressource personnalisée.'],
  ])('has a dedicated empty state for %s', (label, path, key, message) => {
    initialEmpty(); select(label); flush(path, { [key]: [] });
    expect(root().textContent).toContain(message);
  });

  it('caches completed collections locally without refetching on category changes', () => {
    initialEmpty(); select('Ressources'); flush('resources', { resources: [resource] });
    select('Capacités'); select('Ressources');
    http.expectNone('/api/reference/custom/resources');
    expect(root().textContent).toContain('Souffle');
    expect(root().textContent).not.toContain('Chargement');
  });

  it('keeps concurrent responses attached to their own category', () => {
    start(); select('Ressources');
    flush('features', { features: [] });
    expect(root().textContent).toContain('Chargement');
    expect(root().textContent).not.toContain('Aucune capacité');
    flush('resources', { resources: [resource] });
    expect(root().textContent).toContain('Souffle');
  });

  it('isolates failures and retries only the failed collection', () => {
    start();
    http.expectOne('/api/reference/custom/features').flush({}, { status: 500, statusText: 'Error' });
    fixture.detectChanges();
    expect(root().querySelector('[role="alert"]')?.textContent).toContain('Impossible de charger');
    expect(root().textContent).not.toContain('Aucune capacité');
    select('Ressources'); flush('resources', { resources: [] }); select('Capacités');
    root().querySelector<HTMLButtonElement>('.collection-state button')!.click(); fixture.detectChanges();
    expect(root().textContent).toContain('Chargement');
    flush('features', { features: [] });
    expect(root().querySelector('[role="alert"]')).toBeNull();
  });

  it('offers consultation only, with no editor or mutation actions', () => {
    initialEmpty();
    expect(root().querySelectorAll('form, input, textarea, dialog')).toHaveLength(0);
    expect(root().querySelectorAll('button')).toHaveLength(4);
    expect(root().textContent).not.toMatch(/Créer|Modifier|Supprimer/);
  });

  it('cancels pending HTTP when the catalogue is destroyed', () => {
    start(); const request = http.expectOne('/api/reference/custom/features');
    fixture.destroy(); expect(request.cancelled).toBe(true);
  });

  it('opens the real guarded route inside the MJ navigation without a campaign', async () => {
    const harness = await RouterTestingHarness.create('/custom-content');
    const request = http.expectOne('/api/reference/custom/features');
    expect(request.request.method).toBe('GET'); request.flush({ features: [] });
    harness.detectChanges();
    const page = harness.routeNativeElement!;
    expect(page.querySelector('h1')?.textContent).toBe('Contenu personnalisé');
    expect(page.querySelector('.primary-navigation a[href="/custom-content"]')?.textContent).toContain('Contenu personnalisé');
    expect(page.querySelector('.breadcrumbs')?.textContent).toContain('Contenu personnalisé');
    expect(page.querySelector('.breadcrumbs')?.textContent).not.toContain('Campagnes');
    expect(routes.find(route => route.path === '')?.canActivate).toHaveLength(1);
    http.expectNone('/api/campaigns');
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
    http.expectOne('/api/reference/custom/features').flush({ features: [] });
    harness.detectChanges();
    expect(harness.routeNativeElement!.querySelector('.breadcrumbs')?.textContent).toContain('Contenu personnalisé');
    expect(harness.routeNativeElement!.querySelector('.breadcrumbs')?.getAttribute('aria-busy')).toBe('false');
    expect(harness.routeNativeElement!.querySelector('.primary-navigation')?.textContent).not.toContain('Sessions');
    http.verify();
  });
});

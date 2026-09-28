import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CustomContent } from '../custom-content';
import { CustomResource } from '@core/models/custom-content.model';

describe('CUSTOM resource editor in the catalogue', () => {
  let fixture: ComponentFixture<CustomContent>;
  let http: HttpTestingController;
  const base = '/api/reference/custom/resources';
  const resource: CustomResource = { id: 1, name: 'Bêta', description: null, slug: 'custom-secret',
    maximumType: 'fixed', rechargeType: 'long-rest', baseMaximum: 3, multiplier: 2,
    minimumMaximum: 1, scalingAbility: null };
  const other: CustomResource = { ...resource, id: 2, name: 'Delta' };
  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    http = TestBed.inject(HttpTestingController);
    fixture = TestBed.createComponent(CustomContent); fixture.detectChanges();
    http.expectOne('/api/reference/custom/features').flush({ features: [] }); fixture.detectChanges();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
  });
  afterEach(() => { http.verify(); vi.restoreAllMocks(); });
  const root = (): HTMLElement => fixture.nativeElement;
  function click(text: string, selector = 'button') {
    const button = Array.from(root().querySelectorAll<HTMLButtonElement>(selector)).find(button => button.textContent?.trim() === text);
    expect(button, text).toBeDefined(); button!.click(); fixture.detectChanges();
  }
  function load(items: CustomResource[] = [resource, other]) {
    click('Ressources', '.categories button'); http.expectOne(base).flush({ resources: items }); fixture.detectChanges();
  }
  function field(name: string): HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement {
    return root().querySelector(`[formControlName="${name}"]`)!;
  }
  function set(name: string, value: string) {
    const input = field(name); input.value = value;
    input.dispatchEvent(new Event(input.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); fixture.detectChanges();
  }
  function submit() { root().querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); fixture.detectChanges(); }
  function titles() { return Array.from(root().querySelectorAll('article h3')).map(item => item.textContent?.trim()); }

  it('keeps the three other categories read-only, even with items', () => {
    expect(root().textContent).not.toMatch(/Créer|Modifier|Supprimer/);
    for (const [label, path, body] of [
      ['Attributions de capacités', 'feature-rules', { rules: [{ id: 1, featureDefinition: { name: 'Vision' }, source: { name: 'Elfe', type: 'race' }, unlockLevel: 1, progressionThreshold: null }] }],
      ['Règles de ressources', 'resource-rules', { rules: [{ id: 1, resourceDefinition: { name: 'Charge' }, source: { name: 'Elfe', type: 'race' }, unlockLevel: 1, maximumBonus: 0 }] }],
    ] as const) {
      click(label, '.categories button'); http.expectOne('/api/reference/custom/' + path).flush(body); fixture.detectChanges();
      expect(root().textContent).not.toMatch(/Créer|Modifier|Supprimer/);
      expect(root().querySelector('form')).toBeNull();
    }
  });

  it('shows the form with the exact French enum options and conditional fields', () => {
    load(); click('Créer une ressource');
    expect(Array.from((field('rechargeType') as HTMLSelectElement).options).map(option => option.value)).toEqual(['none', 'short-rest', 'long-rest']);
    expect(Array.from((field('maximumType') as HTMLSelectElement).options).map(option => option.value)).toEqual(['fixed', 'proficiency-bonus', 'ability-modifier']);
    expect(field('multiplier')).toBeNull(); expect(field('scalingAbility')).toBeNull();
    set('maximumType', 'ability-modifier');
    expect(field('multiplier')).not.toBeNull();
    expect(Array.from((field('scalingAbility') as HTMLSelectElement).options).map(option => option.textContent?.trim())).toEqual([
      'Choisir une caractéristique', 'Force', 'Dextérité', 'Constitution', 'Intelligence', 'Sagesse', 'Charisme',
    ]);
    set('name', 'Test'); submit(); http.expectNone(request => request.method === 'POST');
    expect(root().querySelector('[role="alert"]')).not.toBeNull();
    set('maximumType', 'proficiency-bonus'); expect(field('scalingAbility')).toBeNull();
    expect(root().querySelectorAll('[formControlName="slug"], [formControlName="origin"], [formControlName="owner"], [formControlName="storedValuesConfig"]')).toHaveLength(0);
  });

  it.each(['', '   ', 'x'.repeat(151)])('rejects invalid names %s', name => {
    load(); click('Créer une ressource'); set('name', name); submit();
    http.expectNone(request => request.method === 'POST');
    expect(root().textContent).toContain('entre 1 et 150 caractères');
  });

  it.each(['-1', '1.5', '2147483648', ''])('rejects invalid numeric values %s in Angular', value => {
    load(); click('Créer une ressource'); set('name', 'Valide'); set('baseMaximum', value); submit();
    http.expectNone(request => request.method === 'POST'); expect(root().querySelector('[role="alert"]')).not.toBeNull();
  });

  it('validates multiplier and minimum, then sends a typed ability payload', () => {
    load(); click('Créer une ressource'); set('name', 'Calculée'); set('maximumType', 'ability-modifier');
    const ability = field('scalingAbility') as HTMLSelectElement;
    ability.selectedIndex = 5; ability.dispatchEvent(new Event('change', { bubbles: true }));
    set('multiplier', '0'); submit(); http.expectNone(request => request.method === 'POST');
    set('multiplier', '2'); set('minimumMaximum', '-1'); submit(); http.expectNone(request => request.method === 'POST');
    set('minimumMaximum', '1'); submit();
    const request = http.expectOne(base); expect(request.request.body.scalingAbility).toBe('wisdom');
    expect(request.request.body.multiplier).toBe(2); expect(request.request.body.minimumMaximum).toBe(1);
    request.flush({ resource: { ...resource, id: 3, name: 'Calculée', maximumType: 'ability-modifier', scalingAbility: 'wisdom' } }); fixture.detectChanges();
  });

  it('creates via a whitelisted trimmed payload, prevents duplicate submit and inserts in order without GET', () => {
    load(); click('Créer une ressource'); set('name', ' Alpha '); set('description', ' '); submit(); submit();
    const request = http.expectOne(base); expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ name: 'Alpha', description: null, rechargeType: 'none', maximumType: 'fixed', baseMaximum: 0, multiplier: 1, minimumMaximum: 0, scalingAbility: null });
    expect(root().textContent).toContain('Enregistrement…');
    expect(Array.from(root().querySelectorAll<HTMLButtonElement>('.categories button')).every(button => button.disabled)).toBe(true);
    request.flush({ resource: { ...resource, id: 3, name: 'Alpha' } }); fixture.detectChanges();
    expect(root().querySelector('form')).toBeNull(); expect(titles()).toEqual(['Alpha', 'Bêta', 'Delta']);
    http.expectNone(base);
  });

  it.each([400, 500, 0])('keeps create input after error %s and allows retry', status => {
    load(); click('Créer une ressource'); set('name', 'Conservée'); submit();
    const request = http.expectOne(base);
    if (status === 0) request.error(new ProgressEvent('error'));
    else request.flush({ message: '<b>Validation</b>' }, { status, statusText: 'Error' });
    fixture.detectChanges(); expect(field('name').value).toBe('Conservée'); expect(root().querySelector('[role="alert"]')).not.toBeNull();
    expect(root().querySelector('b')).toBeNull();
    submit(); http.expectOne(base).flush({ resource: { ...resource, id: 3, name: 'Conservée' } }); fixture.detectChanges();
    expect(root().querySelector('form')).toBeNull();
  });

  it('prefills edit and sends only the changed descriptive fields, preserving hidden mechanics', () => {
    load(); click('Modifier', 'article button');
    expect(field('name').value).toBe('Bêta'); expect(field('baseMaximum').value).toBe('3'); expect(field('rechargeType').value).toBe('long-rest');
    set('name', 'Zulu'); set('description', '  Texte  '); submit();
    const request = http.expectOne(base + '/1'); expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({ name: 'Zulu', description: 'Texte' });
    request.flush({ resource: { ...resource, name: 'Zulu', description: 'Texte' } }); fixture.detectChanges();
    expect(titles()).toEqual(['Delta', 'Zulu']); http.expectNone(base);
  });

  it('sends only the changed mechanic when unused', () => {
    load(); click('Modifier', 'article button'); set('baseMaximum', '7'); submit();
    const request = http.expectOne(base + '/1'); expect(request.request.body).toEqual({ baseMaximum: 7 });
    request.flush({ resource: { ...resource, baseMaximum: 7 } }); fixture.detectChanges();
    expect(root().textContent).toContain('Maximum de base : 7');
  });

  it('clears an obsolete ability and does not retain an invalid hidden multiplier on type change', () => {
    load([{ ...resource, maximumType: 'ability-modifier', scalingAbility: 'wisdom' }]);
    click('Modifier', 'article button'); set('multiplier', '0'); set('maximumType', 'fixed'); submit();
    const request = http.expectOne(base + '/1');
    expect(request.request.body).toEqual({ maximumType: 'fixed', multiplier: 1, scalingAbility: null });
    request.flush({ resource: { ...resource, multiplier: 1 } }); fixture.detectChanges();
  });

  it('orders identical names by ID and preserves the mutation in the local cache', () => {
    load(); click('Créer une ressource'); set('name', 'Bêta'); submit();
    http.expectOne(base).flush({ resource: { ...resource, id: 3, description: 'Nouvelle' } }); fixture.detectChanges();
    const cards = root().querySelectorAll('article');
    expect(titles()).toEqual(['Bêta', 'Bêta', 'Delta']);
    expect(cards[0].textContent).not.toContain('Nouvelle'); expect(cards[1].textContent).toContain('Nouvelle');
    click('Capacités', '.categories button'); click('Ressources', '.categories button');
    expect(titles()).toEqual(['Bêta', 'Bêta', 'Delta']); http.expectNone(base);
  });

  it('refreshes linked read-only summaries on revisit after renaming, without refetching resources', () => {
    load(); click('Modifier', 'article button'); set('name', 'Renommée'); submit();
    http.expectOne(base + '/1').flush({ resource: { ...resource, name: 'Renommée' } }); fixture.detectChanges();
    http.expectNone(request => request.method === 'GET');
    click('Capacités', '.categories button');
    http.expectOne('/api/reference/custom/features').flush({ features: [] }); fixture.detectChanges();
    click('Ressources', '.categories button'); http.expectNone(base);
    expect(titles()).toEqual(['Delta', 'Renommée']);
  });

  it('preserves all inputs on mechanical 409 and subsequently saves descriptions only', () => {
    load(); click('Modifier', 'article button'); set('name', 'Nouveau nom'); set('baseMaximum', '9'); submit();
    http.expectOne(base + '/1').flush({}, { status: 409, statusText: 'Conflict' }); fixture.detectChanges();
    expect(field('name').value).toBe('Nouveau nom'); expect(field('baseMaximum').value).toBe('9');
    expect(root().textContent).toContain('paramètres mécaniques ne peuvent plus être modifiés');
    expect(root().querySelectorAll('fieldset')[1].disabled).toBe(true);
    set('description', 'Descriptif possible'); submit();
    const request = http.expectOne(base + '/1'); expect(request.request.body).toEqual({ name: 'Nouveau nom', description: 'Descriptif possible' });
    request.flush({ resource: { ...resource, name: 'Nouveau nom', description: 'Descriptif possible' } }); fixture.detectChanges();
    expect(root().textContent).toContain('Maximum de base : 3');
    expect(root().textContent).not.toContain('Maximum de base : 9');
  });

  it.each([400, 404, 500])('preserves edit values on %s', status => {
    load(); click('Modifier', 'article button'); set('name', 'Saisie'); submit();
    http.expectOne(base + '/1').flush({ message: 'Valeur refusée' }, { status, statusText: 'Error' }); fixture.detectChanges();
    expect(field('name').value).toBe('Saisie'); expect(titles()).toEqual(['Bêta', 'Delta']);
    expect(root().querySelector('[role="alert"]')).not.toBeNull();
  });

  it('asks for deletion confirmation, honors cancellation, and removes only after 204', () => {
    load(); vi.mocked(window.confirm).mockReturnValueOnce(false); click('Supprimer', 'article button'); http.expectNone(base + '/1');
    expect(window.confirm).toHaveBeenCalledWith('Supprimer la ressource « Bêta » ?');
    click('Supprimer', 'article button'); const request = http.expectOne(base + '/1'); expect(request.request.method).toBe('DELETE');
    expect(titles()).toEqual(['Bêta', 'Delta']); expect(root().textContent).toContain('Suppression…');
    request.flush(null, { status: 204, statusText: 'No Content' }); fixture.detectChanges(); expect(titles()).toEqual(['Delta']);
  });

  it.each([409, 500])('keeps the card on failed DELETE %s', status => {
    load(); click('Supprimer', 'article button'); http.expectOne(base + '/1').flush({}, { status, statusText: 'Error' }); fixture.detectChanges();
    expect(titles()).toEqual(['Bêta', 'Delta']); expect(root().querySelector('[role="alert"]')).not.toBeNull();
    if (status === 409) expect(root().textContent).toContain('utilisée et ne peut pas être supprimée');
  });

  it('removes a stale card on DELETE 404 without breaking the catalogue', () => {
    load(); click('Supprimer', 'article button'); http.expectOne(base + '/1').flush({}, { status: 404, statusText: 'Not Found' }); fixture.detectChanges();
    expect(titles()).toEqual(['Delta']); expect(root().textContent).toContain('n’est plus disponible');
  });

  it('confirms abandonment on category change, never autosaves and retains the resource cache', () => {
    load(); click('Modifier', 'article button'); set('name', 'Abandonnée');
    vi.mocked(window.confirm).mockReturnValueOnce(false); click('Capacités', '.categories button');
    expect(root().querySelector('form')).not.toBeNull();
    click('Capacités', '.categories button'); expect(root().querySelector('form')).toBeNull();
    click('Ressources', '.categories button'); expect(titles()).toEqual(['Bêta', 'Delta']);
    http.expectNone(request => request.method !== 'GET');
  });

  it('restores focus to the trigger on cancel and does not PATCH an unchanged edit', async () => {
    load(); const trigger = root().querySelector<HTMLButtonElement>('article button')!;
    click('Modifier', 'article button'); await fixture.whenStable();
    expect(document.activeElement).toBe(field('name'));
    click('Annuler'); await fixture.whenStable(); expect(document.activeElement).toBe(trigger);
    click('Modifier', 'article button'); submit(); http.expectNone(base + '/1'); expect(root().querySelector('form')).toBeNull();
  });
});

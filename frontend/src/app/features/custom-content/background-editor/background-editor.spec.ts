import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CustomContent } from '../custom-content';
import { CustomBackground } from '@core/models/custom-background.model';

describe('Background editor integrated in custom content', () => {
  let fixture: ComponentFixture<CustomContent>;
  let http: HttpTestingController;
  const base = '/api/reference/custom/backgrounds';
  const item: CustomBackground = { id: 8, origin: 'CUSTOM', mechanicsLocked: false, name: 'Vagabond des Brumes', slug: 'vagabond-des-brumes', description: 'Talisman', equipment: null,
    featureName: null, featureDescription: null, personalityTraits: ['Calme', 'Curieux'], ideals: [], bonds: [], flaws: [],
    proficiencyRules: [{ id: 12, label: 'Compétences', choiceCount: 0, optionIds: [1, 2, 3] }], languageRules: [] };
  const root = (): HTMLElement => fixture.nativeElement;
  async function settle() { fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges(); }
  async function click(text: string, scope: ParentNode = root()) {
    const button = Array.from(scope.querySelectorAll<HTMLButtonElement>('button')).find(b => b.textContent?.trim() === text);
    expect(button).toBeDefined(); button!.click(); await settle();
  }
  async function set(name: string, value: string) {
    const field = root().querySelector<HTMLInputElement>(`[name="${name}"]`)!;
    field.value = value; field.dispatchEvent(new Event('input', { bubbles: true })); await settle();
  }
  async function load(items: CustomBackground[] = [item]) {
    await click('Historiques');
    http.expectOne(base).flush({ backgrounds: items });
    http.expectOne(base + '/options').flush({ proficiencies: [
      { id: 1, name: 'Discrétion', slug: 'stealth', type: 'SKILL' }, { id: 2, name: 'Survie', slug: 'survival', type: 'SKILL' }, { id: 3, name: 'Perception', slug: 'perception', type: 'SKILL' },
    ], languages: [] });
    await settle();
  }
  beforeEach(async () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    http = TestBed.inject(HttpTestingController);
    fixture = TestBed.createComponent(CustomContent); fixture.detectChanges();
    http.expectOne('/api/reference/custom/features').flush({ features: [] }); await settle();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
  });
  afterEach(() => { http.verify(); vi.restoreAllMocks(); });

  it('shows official records read-only without mutation buttons', async () => {
    await load([{ ...item, origin: 'OFFICIAL', mechanicsLocked: true }]);
    expect(root().textContent).toContain('OFFICIAL');
    await click('Consulter');
    expect(root().querySelector('form button[type="submit"]')).toBeNull();
    expect(root().querySelector('form fieldset')?.hasAttribute('disabled')).toBe(true);
    expect(root().textContent).not.toContain('Supprimer');
  });
  it('generates an editable slug, saves and refreshes the collection', async () => {
    await load([]); await click('Créer un historique');
    await set('name', 'Égaré des Brumes');
    expect((root().querySelector('[name="slug"]') as HTMLInputElement).value).toBe('egare-des-brumes');
    await set('slug', 'mon-historique'); await set('name', 'Autre nom');
    expect((root().querySelector('[name="slug"]') as HTMLInputElement).value).toBe('mon-historique');
    await click('Enregistrer');
    const request = http.expectOne(base); expect(request.request.method).toBe('POST');
    expect(request.request.body.slug).toBe('mon-historique'); expect(request.request.body).not.toHaveProperty('ownerId');
    request.flush({ background: { ...item, name: 'Autre nom', slug: 'mon-historique' } }); await settle();
    expect(root().querySelector('form')).toBeNull(); expect(root().textContent).toContain('Autre nom');
  });
  it('locks used mechanics but permits narrative and equipment edits, retaining rule IDs', async () => {
    await load([{ ...item, mechanicsLocked: true }]); await click('Modifier');
    expect((root().querySelector('[name="slug"]') as HTMLInputElement).disabled).toBe(true);
    expect((root().querySelector('[name="equipment"]') as HTMLTextAreaElement).disabled).toBe(false);
    await set('equipment', 'Sac de voyage'); await set('personalityTraits1', 'Très curieux');
    await click('Enregistrer');
    const request = http.expectOne(base + '/8');
    expect(request.request.body).not.toHaveProperty('proficiencyRules');
    expect(request.request.body).not.toHaveProperty('languageRules');
    expect(request.request.body).not.toHaveProperty('slug');
    expect(request.request.body.personalityTraits).toEqual(['Calme', 'Très curieux']);
    expect(request.request.body.equipment).toBe('Sac de voyage');
    request.flush({ background: { ...item, mechanicsLocked: true, equipment: 'Sac de voyage' } }); await settle();
  });
  it('keeps entered values on conflict and asks before changing category', async () => {
    await load(); await click('Modifier'); await set('name', 'Modifié'); await click('Enregistrer');
    http.expectOne(base + '/8').flush({ message: 'Conflit de slug' }, { status: 409, statusText: 'Conflict' }); await settle();
    expect(root().textContent).toContain('Conflit de slug');
    expect((root().querySelector('[name="name"]') as HTMLInputElement).value).toBe('Modifié');
    vi.mocked(window.confirm).mockReturnValue(false); await click('Ressources');
    expect(root().querySelector('form')).not.toBeNull(); http.expectNone('/api/reference/custom/resources');
  });
  it('deletes unused custom records and updates the list', async () => {
    await load(); await click('Supprimer');
    const request = http.expectOne(base + '/8'); expect(request.request.method).toBe('DELETE');
    request.flush(null, { status: 204, statusText: 'No Content' }); await settle();
    expect(root().querySelectorAll('article')).toHaveLength(0);
  });
});

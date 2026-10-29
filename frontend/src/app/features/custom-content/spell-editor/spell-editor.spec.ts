import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CustomContent } from '../custom-content';
import { Spell, SpellGrant, SpellGrantOptions } from '@core/models/spell.model';
import { By } from '@angular/platform-browser';
import { SpellGrants } from './spell-grants';

describe('Spell editor in personal content', () => {
  let fixture: ComponentFixture<CustomContent>;
  let http: HttpTestingController;
  const base = '/api/reference/custom/spells';
  const item: Spell = { id: 5, slug: 'fixture', name: 'Sort fictif', originalName: null, level: 1, school: 'illusion', castingTime: 'Test', range: 'Test', duration: 'Test', description: 'Fiction', higherLevels: null,
    materialDescription: null, materialCostCp: null, rulesVariant: null, sourceCode: null, sourceReference: null, sourceUrl: null, verbal: true, somatic: false, material: false, materialConsumed: false, concentration: false, ritual: false,
    classIds: [], classes: [], origin: 'CUSTOM', edition: '2014', importKey: null, editable: true, deletable: true };
  const root = (): HTMLElement => fixture.nativeElement;
  async function settle() { fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges(); }
  async function click(text: string, scope: ParentNode = root()) {
    const button = scope === root() && ['Consulter', 'Modifier'].includes(text) ? scope.querySelector<HTMLButtonElement>('.spell-card__select') ?? undefined : Array.from(scope.querySelectorAll<HTMLButtonElement>('button')).find(b => b.textContent?.trim() === text);
    expect(button).toBeDefined(); button!.click(); await settle();
  }
  function flush(items: Spell[] = [item], total = items.length, page = 1) {
    http.expectOne(r => r.url === base).flush({ spells: items, total, page, pageSize: 25 });
    http.expectOne(base + '/options').flush({ schools: [{ value: 'illusion', label: 'Illusion' }, { value: 'abjuration', label: 'Abjuration' }], classes: [{ id: 8, name: 'Classe officielle', origin: 'OFFICIAL' }, { id: 9, name: 'Classe personnelle', origin: 'CUSTOM' }] });
  }
  async function load(items: Spell[] = [item]) { await click('Sorts'); flush(items); await settle(); }
  async function set(name: string, value: string) {
    const field = root().querySelector<HTMLInputElement>(`.spell-form [name="${name}"]`)!;
    field.value = value; field.dispatchEvent(new Event('input', { bubbles: true })); await settle();
  }
  const grant: SpellGrant = { id: 12, spellId: 5, subclassId: 4, subclassName: 'Sous-classe personnelle', activationClassId: 8, activationClassName: 'Classe officielle', castingClassId: 8, castingClassName: 'Classe officielle', mode: 'add-to-list', unlockLevel: 1, slotUsage: null, restrictions: null, resourceId: null, resourceName: null, resourceCost: null, origin: 'CUSTOM', editable: true };
  const grantOptions: SpellGrantOptions = { subclasses: [{ id: 4, name: 'Sous-classe personnelle', classId: 8, className: 'Classe officielle' }], classes: [{ id: 8, name: 'Classe officielle' }], resources: [{ id: 3, name: 'Ressource personnelle' }], modes: [{ value: 'add-to-list', label: 'Ajout à la liste' }, { value: 'grant-known', label: 'Automatiquement connu' }, { value: 'grant-always-prepared', label: 'Toujours préparé' }, { value: 'grant-cast', label: 'Accès particulier' }] };
  const grantRoot = (): HTMLElement => root().querySelector('app-spell-grants')!;
  const grantEditor = (): SpellGrants => fixture.debugElement.query(By.directive(SpellGrants)).componentInstance;
  async function setGrant(name: string, value: string) {
    const field = grantRoot().querySelector<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>(`app-reference-picker[name="${name}"] select, input[name="${name}"], select[name="${name}"], textarea[name="${name}"]`)!;
    if (field instanceof HTMLSelectElement) {
      field.selectedIndex = Array.from(field.options).findIndex(option => option.textContent?.trim() === value);
      field.dispatchEvent(new Event('change', { bubbles: true }));
    } else { field.value = value; field.dispatchEvent(new Event('input', { bubbles: true })); }
    await settle();
  }
  async function openGrants(rows: SpellGrant[] = []) {
    await load([{ ...item, origin: 'OFFICIAL', editable: false, deletable: false }]); await click('Consulter'); await click('Afficher les attributions');
    http.expectOne(base + '/5/grants').flush({ grants: rows }); http.expectOne(base + '/grant-options').flush(grantOptions); await settle();
  }
  beforeEach(async () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    http = TestBed.inject(HttpTestingController); fixture = TestBed.createComponent(CustomContent); fixture.detectChanges();
    const initialFeatures = http.match('/api/reference/custom/features'); expect(initialFeatures).toHaveLength(3); initialFeatures.forEach(request => request.flush({ features: [] })); http.expectOne('/api/reference/custom/resources').flush({ resources: [] }); await settle();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
  });
  afterEach(() => { http.verify(); vi.restoreAllMocks(); });

  it('consults official mechanics and classes without mutation controls', async () => {
    await load([{ ...item, origin: 'OFFICIAL', editable: false, deletable: false, classIds: [8], classes: [{ id: 8, name: 'Classe officielle', origin: 'OFFICIAL' }] }]);
    await click('Consulter');
    expect(root().querySelector('.spell-form fieldset')?.hasAttribute('disabled')).toBe(true);
    expect(root().querySelector('.spell-form button[type="submit"]')).toBeNull();
    expect(root().textContent).not.toContain('Supprimer');
    expect(root().textContent).toContain('Classe officielle');
    expect(root().textContent).toContain('Consommation habituelle à l’incantation');
    expect(root().textContent).toContain('La description matérielle fait foi');
    expect(root().textContent).toContain('une case décochée n’exclut pas ces cas particuliers');
  });

  it('edits all mechanics and class memberships using a protected PATCH payload', async () => {
    await load(); await click('Modifier');
    await set('level', '4'); await set('castingTime', 'Nouveau temps'); await set('materialDescription', 'Objet fictif'); await set('materialCostCp', '500');
    for (const name of ['material', 'materialConsumed', 'concentration', 'ritual']) (root().querySelector(`.spell-form [name="${name}"]`) as HTMLInputElement).click();
    const classCheckbox = Array.from(root().querySelectorAll<HTMLInputElement>('.spell-form input[type="checkbox"]')).find(input => input.parentElement?.textContent?.includes('Classe personnelle'))!;
    classCheckbox.click(); await settle(); await click('Enregistrer');
    const req = http.expectOne(base + '/5');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toMatchObject({ level: 4, castingTime: 'Nouveau temps', material: true, materialDescription: 'Objet fictif', materialCostCp: 500, materialConsumed: true, concentration: true, ritual: true, classIds: [9] });
    for (const key of ['slug', 'origin', 'owner', 'edition', 'importKey', 'editable', 'classes']) expect(req.request.body).not.toHaveProperty(key);
    req.flush({ spell: item }); flush(); await settle();
    expect(root().querySelector('.spell-form')).toBeNull();
  });

  it('creates only custom content and keeps rejected relation edits visible', async () => {
    await load([]); await click('Créer un sort personnalisé');
    for (const [name, value] of Object.entries({ name: 'Création', slug: 'creation', castingTime: 'Test', range: 'Test', duration: 'Test', description: 'Fiction' })) await set(name, value);
    await click('Enregistrer');
    const req = http.expectOne(base); expect(req.request.method).toBe('POST'); expect(req.request.body).not.toHaveProperty('origin');
    req.flush({ message: 'Classe indisponible.' }, { status: 400, statusText: 'Bad Request' }); await settle();
    expect(root().textContent).toContain('Classe indisponible.');
    expect((root().querySelector('.spell-form [name="name"]') as HTMLInputElement).value).toBe('Création');
  });

  it('submits class and level filters to the backend before pagination', async () => {
    await load();
    const select = root().querySelector('.filters [name="classId"]') as HTMLSelectElement;
    select.value = '8'; select.dispatchEvent(new Event('change', { bubbles: true }));
    const level = root().querySelector('.filters [name="level"]') as HTMLSelectElement;
    level.value = '0'; level.dispatchEvent(new Event('change', { bubbles: true })); await settle(); await click('Rechercher');
    const req = http.expectOne(r => r.url === base);
    expect(req.request.params.get('classId')).toBe('8'); expect(req.request.params.get('level')).toBe('0');
    req.flush({ spells: [item], total: 30, page: 1, pageSize: 25 }); http.expectOne(base + '/options').flush({ schools: [], classes: [] }); await settle();
    await click('Suivant');
    const next = http.expectOne(r => r.url === base); expect(next.request.params.get('page')).toBe('2'); expect(next.request.params.get('classId')).toBe('8');
    next.flush({ spells: [], total: 30, page: 2, pageSize: 25 }); http.expectOne(base + '/options').flush({ schools: [], classes: [] }); await settle();
  });

  it('protects unsaved edits when switching sections and blocks linked deletion', async () => {
    await load([{ ...item, deletable: false, classIds: [8], classes: [{ id: 8, name: 'Classe officielle', origin: 'OFFICIAL' }] }]);
    const deleteButton = Array.from(root().querySelectorAll<HTMLButtonElement>('button')).find(button => button.textContent?.trim() === 'Supprimer')!;
    expect(deleteButton.disabled).toBe(true);
    await click('Modifier'); await set('name', 'Non enregistré'); vi.mocked(window.confirm).mockReturnValue(false);
    await click('Capacités'); expect(root().querySelector('.spell-form')).not.toBeNull();
  });

  it('deletes own unlinked spell and reloads the catalogue', async () => {
    await load(); await click('Supprimer');
    const req = http.expectOne(base + '/5'); expect(req.request.method).toBe('DELETE'); req.flush(null); flush([]); await settle();
    expect(root().textContent).toContain('Sort supprimé.');
  });

  it.each(['add-to-list', 'grant-known', 'grant-always-prepared', 'grant-cast'])('creates %s from a CUSTOM subclass on an OFFICIAL spell without saving the spell', async mode => {
    await openGrants(); await click('Ajouter une attribution personnelle');
    await setGrant('subclassId', 'Sous-classe personnelle — Classe officielle');
    await setGrant('mode', grantOptions.modes.find(m => m.value === mode)!.label);
    await setGrant('castingClassId', 'Classe officielle'); await setGrant('unlockLevel', '3');
    if (mode === 'grant-cast') {
      await setGrant('slotUsage', 'Non requis'); await setGrant('restrictions', 'Condition fictive');
      await setGrant('resourceId', 'Ressource personnelle'); await setGrant('resourceCost', '2');
    }
    await settle(); await click('Enregistrer l’attribution');
    const req = http.expectOne(base + '/5/grants'); expect(req.request.method).toBe('POST');
    expect(req.request.body).toMatchObject({ subclassId: 4, mode, unlockLevel: 3, castingClassId: 8 });
    expect(req.request.body).not.toHaveProperty('origin'); expect(req.request.body).not.toHaveProperty('owner');
    expect(root().querySelector<HTMLButtonElement>('.categories button')!.disabled).toBe(true);
    http.expectNone(base + '/5');
    req.flush({ grant: { ...grant, ...req.request.body } }); await settle();
    expect(grantEditor().editing()).toBe(false); expect(grantRoot().textContent).toContain('Sous-classe personnelle');
    expect(root().querySelector('.spell-form fieldset')?.hasAttribute('disabled')).toBe(true);
  });

  it('consults OFFICIAL grants without edit/delete controls and displays resolved names', async () => {
    await openGrants([{ ...grant, origin: 'OFFICIAL', editable: false }]);
    expect(grantRoot().textContent).toContain('Classe officielle');
    expect(Array.from(grantRoot().querySelectorAll('button')).map(b => b.textContent?.trim())).not.toContain('Modifier');
    expect(Array.from(grantRoot().querySelectorAll('button')).map(b => b.textContent?.trim())).not.toContain('Supprimer');
    grantEditor().edit({ ...grant, editable: false }); expect(grantEditor().editing()).toBe(false);
  });

  it('preserves grant identity on PATCH and keeps rejected input', async () => {
    await openGrants([grant]); await click('Modifier', grantRoot());
    await setGrant('unlockLevel', '6'); await click('Enregistrer l’attribution');
    const req = http.expectOne(base + '/5/grants/12'); expect(req.request.method).toBe('PATCH'); expect(req.request.body.unlockLevel).toBe(6);
    for (const key of ['id', 'subclassId', 'spellId', 'origin']) expect(req.request.body).not.toHaveProperty(key);
    req.flush({ message: 'Référence indisponible.' }, { status: 404, statusText: 'Not Found' }); await settle();
    expect(grantRoot().textContent).toContain('Référence indisponible.'); expect(grantEditor().draft.unlockLevel).toBe(6);
    expect(grantEditor().busy()).toBe(false);
  });

  it('guards an unsaved CUSTOM grant on a read-only OFFICIAL spell when leaving the editor or category', async () => {
    await openGrants(); await click('Ajouter une attribution personnelle'); await setGrant('unlockLevel', '7');
    vi.mocked(window.confirm).mockReturnValue(false); await settle(); await click('Fermer'); await click('Capacités');
    expect(root().querySelector('app-spell-grants')).not.toBeNull(); expect(grantEditor().draft.unlockLevel).toBe(7);
    http.expectNone('/api/reference/custom/features');
  });

  it('validates level and class in the form and clears special-cast parameters on mode change', async () => {
    await openGrants(); await click('Ajouter une attribution personnelle');
    await setGrant('subclassId', 'Sous-classe personnelle — Classe officielle'); await setGrant('castingClassId', 'Classe officielle'); await setGrant('unlockLevel', '21');
    expect(grantRoot().querySelector<HTMLButtonElement>('button[type="submit"]')!.disabled).toBe(true);
    await setGrant('unlockLevel', '1'); await setGrant('mode', 'Accès particulier'); await setGrant('slotUsage', 'Avec ou sans emplacement');
    await setGrant('resourceId', 'Ressource personnelle'); await setGrant('resourceCost', '2'); await setGrant('restrictions', 'Test');
    await setGrant('mode', 'Automatiquement connu');
    expect(grantEditor().draft).toMatchObject({ slotUsage: null, resourceId: null, resourceCost: null, restrictions: null });
    expect(grantRoot().querySelector<HTMLButtonElement>('button[type="submit"]')!.disabled).toBe(false);
  });

  it('deletes a personal grant without deleting or modifying its official target', async () => {
    await openGrants([grant]); await click('Supprimer', grantRoot());
    const req = http.expectOne(base + '/5/grants/12'); expect(req.request.method).toBe('DELETE'); req.flush(null); await settle();
    expect(grantRoot().textContent).toContain('Aucune attribution visible.'); http.expectNone(base + '/5');
  });

  it('defaults quota impact to unspecified and sends an explicit owner decision', async () => {
    await openGrants([grant]); await click('Modifier', grantRoot());
    expect(grantEditor().draft.quotaImpact).toBe('unspecified');
    await setGrant('quotaImpact', 'Compte dans le quota');
    await click('Enregistrer l’attribution');
    const req = http.expectOne(base + '/5/grants/12');
    expect(req.request.body.quotaImpact).toBe('counts-toward-quota');
    req.flush({ grant: { ...grant, ...req.request.body } }); await settle();
    http.expectNone(base + '/5');
  });
});

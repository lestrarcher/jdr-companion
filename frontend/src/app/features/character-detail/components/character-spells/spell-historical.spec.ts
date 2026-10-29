import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { beforeEach, afterEach, describe, it, expect, vi } from 'vitest';
import { SpellHistorical } from './spell-historical';

describe('Current spell reconciliation', () => {
  let http: HttpTestingController;
  const base = '/api/characters/24/spell-acquisitions';
  const source = () => ({ policyId: 4, className: 'Occultiste', warning: '', expectedAcquisitionIds: [90], categories: [
    { key: 'rule:8', label: 'ordinary', ruleId: 8, quota: 'known', maximum: 12, remaining: 2, known: [{ id: 10, name: 'Sort conservé' }], available: [{ id: 11, name: 'Brume', level: 2 }, { id: 12, name: 'Vol', level: 3 }, { id: 13, name: 'Autre', level: 5 }] },
    { key: 'rule:9', label: 'arcanum-6', ruleId: 9, quota: 'free', maximum: 1, remaining: 1, known: [], available: [{ id: 15, name: 'Arcanum', level: 6 }] },
  ] });
  beforeEach(() => { TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] }); http = TestBed.inject(HttpTestingController); });
  afterEach(() => http.verify());
  function mount(player = false) {
    const f = TestBed.createComponent(SpellHistorical); f.componentRef.setInput(player ? 'accessToken' : 'characterId', player ? 'token' : 24); f.detectChanges();
    http.expectNone(base); f.componentInstance.toggle();
    if (player) http.expectOne('/api/public/characters/token/spells/historical').flush({ revision: 7, editable: true, classes: [source()] });
    else { http.expectOne(base).flush({ policies: [{ policyIds: [4], className: 'Occultiste', status: 'policy-configured' }] }); http.expectOne(base + '/4/historical-preview').flush(source()); }
    f.detectChanges(); return f;
  }
  it('shows current quotas and separate arcanums without origin-level forms', () => {
    const f = mount(), text = f.nativeElement.textContent;
    expect(text).toContain('12 au total'); expect(text).toContain('Arcanum mystique de niveau 6');
    expect(text).not.toContain('niveau d’origine'); expect(f.nativeElement.querySelectorAll('input[type=search]').length).toBe(0); expect(f.nativeElement.querySelectorAll('.category-trigger').length).toBe(2);
  });
  it('opens one category at a time and presents selectable mini cards', () => {
    const f = mount(), c = f.componentInstance, s = source();
    expect(f.nativeElement.querySelectorAll('.historical-spell-card').length).toBe(0);
    (f.nativeElement.querySelectorAll('.category-trigger')[0] as HTMLButtonElement).click(); f.detectChanges();
    expect(f.nativeElement.querySelectorAll('.historical-spell-card').length).toBe(3);
    const first = f.nativeElement.querySelector('.historical-spell-card') as HTMLButtonElement;
    first.click(); f.detectChanges();
    expect(first.getAttribute('aria-pressed')).toBe('true');
    (f.nativeElement.querySelectorAll('.category-trigger')[1] as HTMLButtonElement).click(); f.detectChanges();
    expect(f.nativeElement.querySelectorAll('.historical-spell-card').length).toBe(1);
  });
  it('confirms only the open category and keeps other drafts pending', () => {
    const f = mount(), c = f.componentInstance, s = source();
    c.toggleChoice(s, s.categories[0], s.categories[0].available[0]);
    c.toggleChoice(s, s.categories[1], s.categories[1].available[0]);
    c.saveCategory(s, s.categories[0]);
    const req = http.expectOne(base + '/4/historical-completion');
    expect(req.request.body.choices).toEqual([{ spellId: 11, ruleId: 8 }]);
    req.flush({});
    http.expectOne(base).flush({ policies: [{ policyIds: [4], className: 'Occultiste', status: 'policy-configured' }] });
    http.expectOne(base + '/4/historical-preview').flush(source());
    expect(c.selected(s)).toEqual([{ spellId: 15, ruleId: 9 }]);
  });
  it('renders full long names and existing custom, school and generic artwork', () => {
    const f = mount(), c = f.componentInstance, s = source();
    const spells = [
      { id: 219, name: 'Brume', level: 2, school: 'conjuration' },
      { id: 10001, name: 'Lance à rayon psychique de Raulothim', level: 4, school: 'enchantment' },
      { id: 10002, name: 'Nuée de boules de neige de Snilloc', level: 2, school: null },
    ];
    s.categories[0].available = spells;
    c.view.set({ revision: 1, editable: true, classes: [s] });
    (f.nativeElement.querySelectorAll('.category-trigger')[0] as HTMLButtonElement).click(); f.detectChanges();
    const cards = f.nativeElement.querySelectorAll('.historical-spell-card') as NodeListOf<HTMLButtonElement>;
    expect(cards[0].querySelector('img')?.getAttribute('src')).toBe('/spells/custom/misty-step.svg');
    expect(cards[1].querySelector('img')?.getAttribute('src')).toBe('/spells/schools/enchantment.svg');
    expect(cards[2].querySelector('img')).toBeNull();
    expect(cards[2].querySelector('.fallback-glyph')?.textContent).toBe('✦');
    spells.forEach((spell, index) => expect(cards[index].querySelector('strong')?.textContent).toBe(spell.name));
    const image = cards[0].querySelector('img')!;
    image.dispatchEvent(new Event('error'));
    expect(image.getAttribute('src')).toBe('/spells/schools/conjuration.svg');
    expect(image.hidden).toBe(false);
    image.dispatchEvent(new Event('error'));
    expect(image.hidden).toBe(true);
    expect(c.artwork({ id: 10003, name: 'Inconnu', level: 1, school: 'unknown' })).toBeNull();
  });
  it('filters the full eligible catalogue by name and level', () => {
    const c = mount().componentInstance, s = source(), cat = s.categories[0], key = c.key(s, cat);
    c.query[key] = 'vo'; expect(c.candidates(s, cat).map(s => s.id)).toEqual([12]);
    c.query[key] = ''; c.levelFilter[key] = 5; expect(c.candidates(s, cat).map(s => s.id)).toEqual([13]);
  });
  it('selects multiple spells, caps the category and requires explicit confirmation', () => {
    const c = mount().componentInstance, s = source(), cat = s.categories[0];
    for (const spell of cat.available) c.toggleChoice(s, cat, spell);
    expect(c.selectedCount(s, cat)).toBe(2); c.save(s); http.expectNone(base + '/4/historical-completion');
    c.confirmed[4] = true; c.toggleChoice(s, cat, cat.available[0]); expect(c.confirmed[4]).toBe(false);
  });
  it('submits an atomic non-destructive batch and refreshes parent and quotas', () => {
    const c = mount().componentInstance, s = source(), cat = s.categories[0], saved = vi.fn(); c.saved.subscribe(saved);
    c.toggleChoice(s, cat, cat.available[0]); c.toggleChoice(s, cat, cat.available[1]); c.confirmed[4] = true; c.save(s); c.save(s);
    const req = http.expectOne(base + '/4/historical-completion');
    expect(req.request.body).toEqual({ choices: [{ spellId: 11, ruleId: 8 }, { spellId: 12, ruleId: 8 }], expectedAcquisitionIds: [90], confirmed: true });
    req.flush({}); http.expectOne(base).flush({ policies: [] }); expect(saved).toHaveBeenCalledOnce(); expect(c.selected(s)).toEqual([]);
  });
  it('preserves the selection on infeasible progression and allows correction', () => {
    const c = mount().componentInstance, s = source(), cat = s.categories[0]; c.toggleChoice(s, cat, cat.available[2]); c.confirmed[4] = true; c.save(s);
    http.expectOne(base + '/4/historical-completion').flush({ message: 'Remplacements insuffisants' }, { status: 409, statusText: 'Conflict' });
    expect(c.error()).toBe('Remplacements insuffisants'); expect(c.selected(s)).toEqual([{ spellId: 13, ruleId: 8 }]);
    c.toggleChoice(s, cat, cat.available[2]); expect(c.selected(s)).toEqual([]);
  });
  it('keeps classes independent and uses the player revision', () => {
    const c = mount(true).componentInstance, s = source(), cat = s.categories[0], other = { ...s, policyId: 5 };
    c.toggleChoice(s, cat, cat.available[0]); c.toggleChoice(other, cat, cat.available[0]); c.confirmed[4] = true; c.save(s);
    const req = http.expectOne('/api/public/characters/token/spells/historical-completion'); expect(req.request.body.revision).toBe(7); expect(req.request.body.policyId).toBe(4);
    req.flush({}); http.expectOne('/api/public/characters/token/spells/historical').flush({ revision: 8, editable: true, classes: [source(), other] });
    expect(c.selected(other)).toEqual([{ spellId: 11, ruleId: 8 }]);
  });
  it('shows complete and ambiguous states and blocks writes if refresh fails', () => {
    const f = mount(), c = f.componentInstance, s = source();
    c.view.set({ revision: 1, editable: true, classes: [{ ...s, categories: [] }] }); f.detectChanges(); expect(f.nativeElement.textContent).toContain('Tous les sorts de cette classe sont renseignés.');
    c.view.set({ revision: 1, editable: true, classes: [{ ...s, warning: 'Intervention du MJ', categories: [] }] }); f.detectChanges(); expect(f.nativeElement.textContent).toContain('Intervention du MJ');
    c.load(); http.expectOne(base).flush({}, { status: 503, statusText: 'Unavailable' }); expect(c.view()).toBeNull();
    c.confirmed[4] = true; c.save(s); http.expectNone(base + '/4/historical-completion');
  });
  it('revalidates retained selection after an explicit refresh and clears confirmation', () => {
    const c = mount().componentInstance, s = source(), cat = s.categories[0];
    c.toggleChoice(s, cat, cat.available[0]); c.toggleChoice(s, cat, cat.available[1]); c.confirmed[4] = true; c.load();
    http.expectOne(base).flush({ policies: [{ policyIds: [4], className: 'Occultiste', status: 'policy-configured' }] });
    const fresh = source(); fresh.categories[0].remaining = 1; fresh.categories[0].available = [cat.available[1]];
    http.expectOne(base + '/4/historical-preview').flush(fresh);
    expect(c.selected(s)).toEqual([{ spellId: 12, ruleId: 8 }]); expect(c.confirmed[4]).toBe(false);
  });
});

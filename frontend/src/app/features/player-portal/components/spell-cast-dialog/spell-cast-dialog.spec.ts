import { TestBed } from '@angular/core/testing';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PlayerSpell } from '@core/services/character-session-state-api.service';
import { SpellCastingAvailability } from '@core/models/spell-cast.model';
import { SpellCastDialog } from './spell-cast-dialog';
import { installNativeDialogStub } from '../../../../../testing/native-dialog';

installNativeDialogStub();

const method = (kind: 'standard-slot' | 'pact-slot' | 'ritual' | 'cantrip', castingLevel: number): SpellCastingAvailability['methods'][number] =>
  ({ kind, castingLevel, status: 'available', reason: null, cost: ['ritual', 'cantrip'].includes(kind) ? 0 : 1,
    poolSlug: kind === 'pact-slot' ? 'warlock-pact-slots' : kind === 'standard-slot' ? `spell-slot-${castingLevel}` : null });
function spell(key = 'acquisition:1', methods = [method('standard-slot', 1)]): PlayerSpell {
  return { key, spellId: 1, policyId: 1, name: 'Sort partagé', level: 1, school: 'abjuration', source: key, sourceType: 'class',
    known: true, prepared: true, inSpellbook: false, castingAbility: 'intelligence', spellSaveDc: 13, spellAttackBonus: 5,
    details: { description: 'Description', higherLevels: null, castingTime: '1 action', range: '18 m', duration: '1 minute',
      verbal: true, somatic: true, material: false, materialDescription: null, concentration: false, ritual: false },
    castingAvailability: { sourceKey: key, policyId: 1, status: 'available', reason: null, methods } };
}
function setup(sources = [spell()]) {
  const fixture = TestBed.createComponent(SpellCastDialog);
  fixture.componentRef.setInput('spell', sources[0]); fixture.componentRef.setInput('sources', sources);
  fixture.componentRef.setInput('school', 'Abjuration'); fixture.detectChanges();
  return { fixture, c: fixture.componentInstance };
}
afterEach(() => TestBed.resetTestingModule());
describe('Shared casting dialog', () => {
  it('requires an explicit resource or slot choice and emits only on confirmation', () => {
    const resource: SpellCastingAvailability['methods'][number] = { kind: 'resource', castingLevel: 2, poolSlug: 'feat-use', cost: 2, status: 'available', reason: null };
    const entry = { ...spell('feature:1', [resource, method('standard-slot', 2)]), sourceType: 'feature' as const };
    const { fixture, c } = setup([entry]); const done = vi.fn(); c.confirmed.subscribe(done);
    const counters = [{ id: 'feat-use', currentValue: 3 }];
    fixture.componentRef.setInput('resources', counters); fixture.detectChanges();
    expect(c.selected()).toBeUndefined(); expect(done).not.toHaveBeenCalled();
    expect(fixture.nativeElement.textContent).toContain('2 unité(s) de ressource de capacité');
    expect(fixture.nativeElement.textContent).toContain('3 rest.');
    fixture.nativeElement.querySelector('[data-method=resource]').click(); fixture.detectChanges();
    expect(done).not.toHaveBeenCalled(); expect(counters[0].currentValue).toBe(3);
    expect(fixture.nativeElement.querySelector('.cast-cost').textContent).toContain('sans emplacement');
    fixture.nativeElement.querySelector('.cast-confirm').click();
    expect(done).toHaveBeenLastCalledWith({ sourceKey: 'feature:1', method: 'resource', castingLevel: 2 });
    c.select(c.standardOptions()[0].key); c.confirm();
    expect(done).toHaveBeenLastCalledWith({ sourceKey: 'feature:1', method: 'standard-slot', castingLevel: 2 });
  });
  it.each(['exhausted', 'insufficient_resource'])('shows a disabled feature resource reason: %s', reason => {
    const resource: SpellCastingAvailability['methods'][number] = { kind: 'resource', castingLevel: 1, poolSlug: 'feat-use', cost: 2, status: 'unavailable', reason };
    const { fixture, c } = setup([{ ...spell('feature:1', [resource]), sourceType: 'feature' }]);
    expect(c.standardOptions()).toEqual([]); expect(c.options()).toEqual([]);
    expect(fixture.nativeElement.querySelector('[data-method=resource]').disabled).toBe(true);
    expect(fixture.nativeElement.textContent).toContain(reason === 'exhausted' ? 'Épuisé' : 'Ressource insuffisante');
    expect(fixture.nativeElement.querySelector('.cast-confirm').disabled).toBe(true);
  });
  it('hides structurally absent slots and inapplicable ritual/pact methods, keeping exhausted slots', () => {
    const methods = Array.from({length:9}, (_, index) => method('standard-slot', index + 1));
    methods[1].status = 'unavailable'; methods[1].reason = 'exhausted';
    for (const option of methods.slice(2)) { option.status = 'unavailable'; option.reason = 'inactive_pool'; }
    methods.push({...method('ritual',1),status:'unavailable',reason:'not_a_ritual'},
      {...method('pact-slot',2),status:'unavailable',reason:'inactive_pool'});
    const { fixture, c } = setup([spell('wizard', methods)]);
    // Even a historical or malformed local counter cannot revive an inactive server pool.
    fixture.componentRef.setInput('resources',[{id:'spell-slot-3',currentValue:1}]); fixture.detectChanges();
    const buttons = fixture.nativeElement.querySelectorAll('.level-option') as NodeListOf<HTMLButtonElement>;
    expect(Array.from(buttons, button => button.querySelector('strong')!.textContent!.trim())).toEqual(['I','II']);
    expect(buttons[0].disabled).toBe(false); expect(buttons[1].disabled).toBe(true);
    expect(fixture.nativeElement.querySelector('.method-option')).toBeNull();
    expect(c.options()).toHaveLength(1);
  });
  it.each(['missing_counter','invalid_counter','duplicate_counter','pool_context_missing'])('keeps compatible unknown pools for %s', reason => {
    const entry = spell('wizard', [{...method('standard-slot',2),status:'unknown',reason},
      {...method('pact-slot',2),status:'unknown',reason}]);
    const { fixture, c } = setup([entry]);
    expect(c.displayOptions()).toHaveLength(2); expect(c.options()).toHaveLength(0);
    const buttons = fixture.nativeElement.querySelectorAll('.cast-option') as NodeListOf<HTMLButtonElement>;
    expect(buttons).toHaveLength(2);
    for (const button of buttons) { expect(button.disabled).toBe(true); expect(button.getAttribute('aria-label')).toContain('État inconnu'); }
  });
  it('retains exhausted pact and ambiguous ritual prerequisites without inventing eligibility', () => {
    const { c } = setup([spell('wizard', [{...method('pact-slot',2),status:'unavailable',reason:'exhausted'},
      {...method('ritual',1),status:'unavailable',reason:'ritual_feature_or_preparation_missing'}])]);
    expect(c.otherOptions()).toHaveLength(2); expect(c.options()).toHaveLength(0);
  });
  it('renders compact Roman levels with informative disabled states and no lower-level slots', () => {
    const methods = [method('standard-slot', 1), method('standard-slot', 3), method('standard-slot', 4), method('standard-slot', 5)];
    methods[2].status = 'unavailable'; methods[2].reason = 'exhausted'; methods[3].status = 'unknown';
    const entry = spell('wizard', methods); entry.level = 3;
    const { fixture, c } = setup([entry]);
    fixture.componentRef.setInput('resources', [{id:'spell-slot-3',currentValue:2},{id:'spell-slot-4',currentValue:0}]); fixture.detectChanges();
    const buttons = fixture.nativeElement.querySelectorAll('.level-option') as NodeListOf<HTMLButtonElement>;
    expect(Array.from(buttons, b => b.querySelector('strong')!.textContent!.trim())).toEqual(['III','IV','V']);
    expect(buttons[0].querySelector('small')!.textContent).toContain('2 rest.');
    expect(buttons[1].disabled).toBe(true); expect(buttons[1].getAttribute('aria-label')).toContain('Épuisé');
    expect(buttons[2].disabled).toBe(true); expect(buttons[2].getAttribute('aria-label')).toContain('État inconnu');
    expect(buttons[0].getAttribute('aria-label')).toContain('wizard · Emplacement standard · niveau 3');
    expect(buttons[0].getAttribute('aria-label')).toContain('coût : 1 emplacement(s)');
    buttons[0].focus(); expect(document.activeElement).toBe(buttons[0]);
    expect(getComputedStyle(buttons[0]).minHeight).toBe('44px');
    expect(getComputedStyle(fixture.nativeElement.querySelector('.levels')).flexWrap).toBe('wrap');
    const selected = c.selected(); c.select(c.displayOptions()[1].key); expect(c.selected()).toBe(selected);
    expect(fixture.nativeElement.querySelector('.availability-note').textContent).toContain('Grisé : indisponible');
  });
  it('preserves separate options when the same level has distinct costs or pools', () => {
    const a = method('standard-slot', 2); const b = {...a,cost:2}; const pact = method('pact-slot',2);
    const { c } = setup([spell('wizard',[a,b,pact])]);
    expect(c.options()).toHaveLength(3); expect(new Set(c.options().map(option=>option.key)).size).toBe(3);
    expect(c.selected()).toBeUndefined();
    c.select(c.options()[1].key); expect(c.selected()?.method.cost).toBe(2);
  });
  it('shows unknown quantities for missing, duplicated or invalid counters without inferring availability', () => {
    const { fixture, c } = setup(); const option = c.options()[0];
    expect(c.remaining(option)).toBeNull();
    for (const resources of [[{id:'spell-slot-1',currentValue:-1}], [{id:'spell-slot-1',currentValue:1.5}],
      [{id:'spell-slot-1',currentValue:2},{id:'spell-slot-1',currentValue:2}]]) {
      fixture.componentRef.setInput('resources', resources); fixture.detectChanges(); expect(c.remaining(option)).toBeNull();
      expect(fixture.nativeElement.querySelector('.level-option').disabled).toBe(false);
    }
    fixture.componentRef.setInput('resources', [{id:'spell-slot-1',currentValue:0}]); fixture.detectChanges();
    expect(c.remaining(option)).toBe(0); expect(c.options()).toHaveLength(1);
  });
  it('opens a native modal, shows facts and preselects its only executable combination', () => {
    const { fixture, c } = setup(); const done = vi.fn(); c.confirmed.subscribe(done);
    expect(fixture.nativeElement.querySelector('dialog').open).toBe(true);
    expect(fixture.nativeElement.textContent).toContain('Abjuration');
    expect(fixture.nativeElement.textContent).toContain('1 action');
    expect(fixture.nativeElement.querySelector('.cast-option').getAttribute('aria-pressed')).toBe('true');
    expect(fixture.nativeElement.querySelector('.source-picker')).toBeNull();
    fixture.nativeElement.querySelector('.cast-confirm').click();
    expect(done).toHaveBeenCalledWith({ sourceKey: 'acquisition:1', method: 'standard-slot', castingLevel: 1 });
  });
  it('requires an explicit choice between levels, methods and exact multiclass sources', () => {
    const sources = [spell('wizard', [method('standard-slot', 1), method('standard-slot', 3), method('ritual', 1)]),
      { ...spell('bard', [method('pact-slot', 3)]), policyId: 2 }];
    const { fixture, c } = setup(sources); const done = vi.fn(); c.confirmed.subscribe(done);
    expect(c.selected()).toBeUndefined(); expect(fixture.nativeElement.querySelector('.cast-confirm').disabled).toBe(true);
    const source = fixture.nativeElement.querySelector('.source-picker select') as HTMLSelectElement;
    source.value = 'wizard'; source.dispatchEvent(new Event('change')); fixture.detectChanges();
    fixture.nativeElement.querySelectorAll('.level-option')[1].click(); fixture.detectChanges(); c.confirm();
    expect(done).toHaveBeenLastCalledWith({ sourceKey: 'wizard', method: 'standard-slot', castingLevel: 3 });
    source.value = 'bard'; source.dispatchEvent(new Event('change')); fixture.detectChanges(); c.confirm();
    expect(done).toHaveBeenLastCalledWith({ sourceKey: 'bard', method: 'pact-slot', castingLevel: 3 });
    expect(c.selected()?.source.policyId).toBe(2);
  });
  it('keeps a class option when the feat grant is unavailable, ignoring the global status', () => {
    const feat = spell('feature:1'); feat.sourceType = 'feature'; feat.castingAvailability!.methods[0].status = 'unknown';
    const learned = spell(); learned.castingAvailability!.status = 'unknown';
    const { c } = setup([feat, learned]);
    expect(c.options()).toHaveLength(1); expect(c.selected()?.source.key).toBe(learned.key);
    expect(c.activeSource()?.key).toBe(learned.key);
  });
  it('does not offer unknown, exhausted, unresolved or mismatched methods or unrelated spells', () => {
    const sources = [spell('unknown'), spell('empty'), spell('unresolved'), spell('wrong'), { ...spell('other'), spellId: 2 }];
    sources[0].castingAvailability!.methods[0].status = 'unknown';
    sources[1].castingAvailability!.methods[0].status = 'unavailable';
    sources[2].castingAvailability!.methods[0].kind = 'normal';
    sources[3].castingAvailability!.sourceKey = 'mismatch';
    const { fixture, c } = setup(sources); const done = vi.fn(); c.confirmed.subscribe(done); c.confirm();
    expect(c.options()).toEqual([]); expect(done).not.toHaveBeenCalled();
    expect(fixture.nativeElement.querySelector('.cast-confirm').disabled).toBe(true);
    expect(fixture.nativeElement.textContent).toContain('Aucun moyen');
  });
  it.each(['ritual', 'cantrip'] as const)('offers %s with its server level and no slot', kind => {
    const entry = spell('free', [method(kind, kind === 'cantrip' ? 0 : 1)]);
    entry.level = kind === 'cantrip' ? 0 : 1;
    const { fixture, c } = setup([entry]);
    expect(c.selected()?.method.castingLevel).toBe(entry.level);
    expect(fixture.nativeElement.textContent).toContain('Aucun emplacement consommé.');
    expect(fixture.nativeElement.querySelector('.higher-levels')).toBeNull();
  });
  it.each([null, '', 'Un dé supplémentaire.', 'Aucun effet supplémentaire à un niveau supérieur'])('uses only supplied higher-level information: %s', text => {
    const entry = spell('higher', [method('standard-slot', 3)]); entry.details!.higherLevels = text;
    const { fixture } = setup([entry]);
    expect(fixture.nativeElement.querySelector('.higher-levels').textContent)
      .toContain(text || 'Effet à plus haut niveau non renseigné');
  });
  it('keeps long upcasting text accessible behind a short literal preview', () => {
    const entry = spell('higher', [method('standard-slot', 3)]);
    const text = 'Description officielle.\nDeuxième paragraphe.\n'.repeat(20).trim(); entry.details!.higherLevels = text;
    const { fixture } = setup([entry]);
    expect(fixture.nativeElement.querySelector('.higher-level-preview').textContent.trim()).toBe(text.trim().slice(0,180)+'…');
    const button = fixture.nativeElement.querySelector('.higher-level-toggle') as HTMLButtonElement;
    const paragraph = fixture.nativeElement.querySelector('.higher-level-preview') as HTMLParagraphElement;
    expect(button.type).toBe('button');
    expect(button.getAttribute('aria-controls')).toBe(paragraph.id);
    expect(button.getAttribute('aria-expanded')).toBe('false');
    expect(button.textContent!.trim()).toBe('Lire la suite');
    button.focus(); button.click(); fixture.detectChanges();
    expect(button.getAttribute('aria-expanded')).toBe('true');
    expect(button.textContent!.trim()).toBe('Réduire le texte');
    expect(fixture.nativeElement.querySelectorAll('.higher-levels p')).toHaveLength(1);
    expect(fixture.nativeElement.querySelector('.higher-level-preview')).toBe(paragraph);
    expect(paragraph.textContent).toBe(text);
    expect(getComputedStyle(paragraph).whiteSpace).toBe('pre-wrap');
    expect(document.activeElement).toBe(button);
    button.click(); fixture.detectChanges();
    expect(button.getAttribute('aria-expanded')).toBe('false');
    expect(button.textContent!.trim()).toBe('Lire la suite');
    expect(paragraph.textContent).toBe(text.slice(0,180)+'…');
    expect(fixture.nativeElement.querySelectorAll('.higher-levels p')).toHaveLength(1);
    expect(document.activeElement).toBe(button);
    expect(entry.details!.higherLevels).toBe(text);
  });
  it('cancels without emitting a selection and handles Escape', () => {
    const { fixture, c } = setup(); const done = vi.fn(); const closed = vi.fn();
    c.confirmed.subscribe(done); c.closed.subscribe(closed);
    fixture.nativeElement.querySelector('.cancel').click();
    const event = new Event('cancel', { cancelable: true }); fixture.nativeElement.querySelector('dialog').dispatchEvent(event);
    expect(event.defaultPrevented).toBe(true); expect(closed).toHaveBeenCalledTimes(2); expect(done).not.toHaveBeenCalled();
  });
  it('prevents new selections while unresolved and emits retry without creating a command', () => {
    const { fixture, c } = setup(); const retry = vi.fn(); const done = vi.fn(); c.retried.subscribe(retry); c.confirmed.subscribe(done);
    const pending = { name: 'Historique', source: 'Magicien', command: { revision: 42, requestId: 'unchanged', sourceKey: 'acquisition:1', method: 'standard-slot' as const, castingLevel: 1 } };
    fixture.componentRef.setInput('pending', pending); fixture.componentRef.setInput('uncertain', true);
    fixture.componentRef.setInput('error', 'Résultat incertain'); fixture.detectChanges();
    c.confirm(); expect(done).not.toHaveBeenCalled(); expect(fixture.nativeElement.querySelector('.cast-option')).toBeNull();
    fixture.nativeElement.querySelector('.retry').click(); expect(retry).toHaveBeenCalledTimes(1); expect(c.pending()).toBe(pending);
    fixture.componentRef.setInput('saving', true); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.retry').disabled).toBe(true);
    const closed = vi.fn(); c.closed.subscribe(closed); c.close(); expect(closed).not.toHaveBeenCalled();
  });
  it('invalidates a selected combination when fresh server methods remove it', () => {
    const entry = spell('choice', [method('standard-slot', 1), method('pact-slot', 2)]);
    const { fixture, c } = setup([entry]); c.select(c.options()[0].key);
    fixture.componentRef.setInput('sources', [spell('choice', [method('pact-slot', 2)])]); fixture.detectChanges();
    expect(c.selected()).toBeUndefined(); expect(fixture.nativeElement.querySelector('.cast-confirm').disabled).toBe(true);
  });
});

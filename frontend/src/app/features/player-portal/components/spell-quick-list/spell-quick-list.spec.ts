import { TestBed } from '@angular/core/testing';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PlayerSpell } from '@core/services/character-session-state-api.service';
import { SpellQuickList } from './spell-quick-list';

function spell(key: string, level: number, name = key, status: 'available' | 'unavailable' | 'unknown' = 'available'): PlayerSpell {
  return { key, spellId: 1, name, level, school: 'evocation', source: key, sourceType: 'class',
    castingAbility: 'intelligence', spellSaveDc: 13, spellAttackBonus: 5, known: true, inSpellbook: false, prepared: null,
    castingAvailability: { sourceKey: key, policyId: 1, status, reason: null,
      methods: [{ kind: level === 0 ? 'cantrip' : 'standard-slot', castingLevel: level,
        poolSlug: level === 0 ? null : `spell-slot-${level}`, cost: level === 0 ? 0 : 1, status, reason: null }] } };
}
function setup(spells: PlayerSpell[]) {
  const fixture = TestBed.createComponent(SpellQuickList);
  fixture.componentRef.setInput('spells', spells); fixture.detectChanges();
  return { fixture, c: fixture.componentInstance };
}
afterEach(() => TestBed.resetTestingModule());
describe('Shared spell quicklist', () => {
  it.each(['directory', 'play'] as const)('restores school icons, colors and compact level badges in %s mode', mode => {
    const { fixture } = setup([
      { ...spell('a', 1), prepared: true },
      { ...spell('b', 2, 'Bouclier', 'unavailable'), school: 'abjuration' },
      { ...spell('c', 3), school: null },
    ]);
    fixture.componentRef.setInput('mode', mode);
    fixture.componentRef.setInput('selectedKey', 'a'); fixture.detectChanges();
    const cards = fixture.nativeElement.querySelectorAll('.quick-spell') as NodeListOf<HTMLButtonElement>;
    expect(Array.from(cards, card => card.querySelector('.spell-icon')!.textContent!.trim())).toEqual(['ϟ', '⬡', '✧']);
    expect(cards[0].dataset['school']).toBe('evocation');
    expect(getComputedStyle(cards[0].querySelector('.spell-icon')!).color).toBe('rgb(242, 174, 119)');
    expect(getComputedStyle(cards[1].querySelector('.spell-icon')!).color).toBe('rgb(145, 203, 224)');
    expect(getComputedStyle(cards[0].querySelector('.spell-level')!).position).toBe('absolute');
    expect(cards[0].querySelector('.spell-level')!.textContent!.trim()).toBe('1');
    expect(cards[0].classList.contains('is-selected')).toBe(true);
    expect(cards[0].querySelector('.prepared-dot')).not.toBeNull();
    expect(cards[0].title).toBe('a · a');
    expect(cards[1].disabled).toBe(true);
    expect(cards[1].classList.contains('is-unavailable')).toBe(true);
    expect(getComputedStyle(cards[1]).filter).toBe('grayscale(1)');
    expect(fixture.nativeElement.querySelector('.quick-source')).toBeNull();
  });
  it('sorts all directory entries by real level and French name without mutating them', () => {
    const entries = [spell('z', 2, 'Zone'), spell('s', 1, 'Soins'), spell('l', 0, 'Lumière'), spell('a', 1, 'Aide', 'unknown')];
    entries[0].castingAvailability!.methods[0].castingLevel = 5;
    const before = structuredClone(entries); const { c } = setup(entries);
    expect(c.entries().map(s => s.name)).toEqual(['Lumière', 'Aide', 'Soins', 'Zone']);
    expect(entries).toEqual(before);
  });
  it('excludes cantrips and unknown levels only in play mode, with optional unavailable filtering', () => {
    const { fixture, c } = setup([spell('zero', 0), spell('one', 1), spell('two', 2, 'two', 'unavailable'), { ...spell('null', 1), level: null }]);
    fixture.componentRef.setInput('mode', 'play'); fixture.detectChanges();
    expect(c.entries().map(s => s.key)).toEqual(['one', 'two']);
    fixture.componentRef.setInput('hideUnavailable', true); fixture.detectChanges();
    expect(c.entries().map(s => s.key)).toEqual(['one']);
  });
  it('retains exact sources of the same spell and uses executable methods instead of global status', () => {
    const granted = spell('feature:1', 1, 'Foulée', 'unknown');
    const learned = spell('acquisition:2', 1, 'Foulée'); learned.castingAvailability!.status = 'unknown';
    const { fixture, c } = setup([granted, learned]); const selected = vi.fn(); c.selected.subscribe(selected);
    const buttons = fixture.nativeElement.querySelectorAll('button');
    expect(buttons[0].disabled).toBe(true); expect(buttons[1].disabled).toBe(false);
    buttons[0].click(); buttons[1].click();
    expect(selected).toHaveBeenCalledExactlyOnceWith(learned);
  });
  it('disables absent, unresolved, exhausted, mismatched and indeterminate methods', () => {
    const missing = spell('missing', 1); delete missing.castingAvailability;
    const unresolved = spell('grant', 1); unresolved.castingAvailability!.methods[0].kind = 'normal';
    const mismatch = spell('mismatch', 1); mismatch.castingAvailability!.sourceKey = 'other';
    const level = spell('level', 1); level.castingAvailability!.methods[0].castingLevel = null;
    const { fixture, c } = setup([missing, unresolved, mismatch, level, spell('zero', 1, 'zero', 'unavailable'), spell('unknown', 1, 'unknown', 'unknown')]);
    const selected = vi.fn(); c.selected.subscribe(selected);
    for (const button of fixture.nativeElement.querySelectorAll('button')) { expect(button.disabled).toBe(true); button.click(); }
    expect(selected).not.toHaveBeenCalled();
  });
  it('accepts rituals and pact methods without inferring any consumption', () => {
    const ritual = spell('ritual', 1); ritual.castingAvailability!.methods[0].kind = 'ritual';
    const pact = spell('pact', 2); pact.castingAvailability!.methods[0].kind = 'pact-slot';
    const { fixture, c } = setup([ritual, pact]); const selected = vi.fn(); c.selected.subscribe(selected);
    for (const entry of c.entries()) c.choose(entry);
    expect(selected).toHaveBeenCalledTimes(2);
    fixture.componentRef.setInput('disabled', true); fixture.detectChanges(); c.choose(pact);
    expect(selected).toHaveBeenCalledTimes(2);
  });
});

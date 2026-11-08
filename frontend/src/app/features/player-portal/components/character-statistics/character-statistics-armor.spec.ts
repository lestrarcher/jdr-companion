import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import { CharacterStatistics } from './character-statistics';

describe('Armor class details', () => {
  it.each(['summary', 'detail'])('keeps %s compact and renders only backend contributions', async mode => {
    const fixture = TestBed.createComponent(CharacterStatistics);
    fixture.componentRef.setInput('abilities', []);
    fixture.componentRef.setInput('mode', mode);
    fixture.componentRef.setInput('armorClass', {
      value: 19, complete: true, source: 'Armure fictive', formula: 'Formule backend', reasons: [], warnings: [],
      contributions: [
        { kind: 'base', source: 'Armure fictive', label: 'Base', value: 14 },
        { kind: 'ability', source: 'Armure fictive', label: 'DEX retenue', value: 2 },
        { kind: 'shield', source: 'Bouclier fictif', label: 'Bouclier', value: 2 },
        { kind: 'bonus', source: 'Anneau fictif', label: 'Protection', value: 1 },
      ],
      excluded: [{ source: 'Bracelets fictifs', label: 'Protection', reason: 'Armure équipée.' }],
    });
    await fixture.whenStable();
    const details = fixture.nativeElement.querySelector('details') as HTMLDetailsElement;
    const summary = details.querySelector('summary')!;
    expect(details.open).toBe(false);
    expect(summary.textContent).toContain('19');
    expect(summary.textContent).not.toContain('Armure fictive');
    expect(summary.querySelector('svg path')?.getAttribute('fill')).toBe('currentColor');
    expect(summary.textContent).not.toContain('Protection');
    summary.click();
    expect(details.open).toBe(true);
    expect(details.textContent).toContain('Bonus DEX');
    expect(details.textContent).toContain('Anneau fictif');
    expect(details.textContent).not.toContain('Armure équipée.');
    expect(details.querySelectorAll('.statistics-armor-part')).toHaveLength(4);
    summary.click();
    expect(details.open).toBe(false);
    fixture.destroy();
  });

  it('does not invent contributions when calculation is incomplete', async () => {
    const fixture = TestBed.createComponent(CharacterStatistics);
    fixture.componentRef.setInput('abilities', []);
    fixture.componentRef.setInput('armorClass', { value: null, complete: false, source: null, formula: null, reasons: ['Profil manquant'] });
    await fixture.whenStable();
    const details = fixture.nativeElement.querySelector('details');
    expect(details.querySelector('summary').textContent).toContain('—');
    expect(details.querySelector('summary').textContent).not.toContain('Calcul incomplet');
    expect(details.querySelectorAll('.statistics-armor-part')).toHaveLength(0);
    expect(details.querySelector('summary').textContent).not.toContain('Profil manquant');
    expect(details.textContent).not.toContain('Profil manquant');
    fixture.destroy();
  });
});

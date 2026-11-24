import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { of } from 'rxjs';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { HitPointBreakdown } from './player-portal/components/hit-point-breakdown/hit-point-breakdown';
import { CharacterHitPointHistory } from './campaign-characters/components/character-hit-point-history/character-hit-point-history';
import { InitiativeControl } from './control-dashboard/components/initiative-control/initiative-control';

afterEach(() => TestBed.resetTestingModule());

describe('Affichage des PV', () => {
  it('affiche le calcul, les libellés, les ajustements et l’épuisement dans un détail compact', async () => {
    const fixture = TestBed.createComponent(HitPointBreakdown);
    fixture.componentRef.setInput('calculation', { baseValue: 10, constitutionModifier: -2, constitutionBonus: -4,
      featureBonus: 2, featureContributions: [{ featureSlug: 'dragon', key: 'hp', label: 'Résistance draconique', value: 2 }],
      maximumValue: 9, complete: true, missingLevelPositions: [] });
    fixture.componentRef.setInput('adjustment', 5);
    fixture.componentRef.setInput('exhaustionLevel', 4);
    fixture.componentRef.setInput('maximum', 7);
    await fixture.whenStable();
    const element: HTMLElement = fixture.nativeElement;
    expect(element.querySelector('details')?.open).toBe(false);
    expect(element.querySelector('summary strong')?.textContent).toBe('7');
    expect(element.textContent).toContain('Gains bruts');
    expect(element.textContent).toContain('Constitution (-2 / niveau)');
    expect(element.textContent).toContain('Résistance draconique');
    expect(element.textContent).toContain('Minimum de 1 PV par niveau');
    expect(element.textContent).toContain('Ajustement du maximum');
    expect(element.textContent).toContain('Épuisement 4');
    fixture.componentRef.setInput('adjustment', 0);
    fixture.componentRef.setInput('exhaustionLevel', 3);
    await fixture.whenStable();
    expect(element.textContent).not.toContain('Ajustement du maximum');
    expect(element.textContent).not.toContain('divisé par 2');
  });

  it('plafonne chaque gain à un minimum de 1 avant d’ajouter les capacités', () => {
    const history = Object.create(CharacterHitPointHistory.prototype) as any;
    history.character = () => ({ totalLevel: 2, hitPoints: { constitutionModifier: -2, featureBonus: 2 } });
    history.entries = [{ gain: 8 }, { gain: 1 }];
    expect(history.projectedMaximumValue()).toBe(9); // 6 + 1 + 2, pas max(2, 9 - 4) + 2.
  });

  it.each([20, 0, null])('utilise le maximum effectif %s pour l’initiative', maximum => {
    const control = Object.create(InitiativeControl.prototype) as any;
    Object.assign(control, { campaignId: () => 1, sessionId: () => 2, participants: signal([]),
      importingCharacters: signal(false), importError: signal(null), draftChanged: { emit: vi.fn() },
      characterSessionStateApi: { list: () => of([{ participating: true,
        character: { id: 1, name: 'Hero', hitPoints: { maximumValue: 40 } },
        state: { hitPoints: { current: 15, effectiveMaximum: maximum } } }]) },
      figureApi: { list: () => of([]) } });
    control.importSessionCharacters();
    expect(control.participants()[0].maximumHitPoints).toBe(maximum);
    expect(control.participants()[0].bloodied).toBe(false);
  });
});

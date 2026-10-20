import { TestBed } from '@angular/core/testing';
import { describe, it, expect, vi } from 'vitest';
import { CharacterProfile } from './character-profile';
import { CharacterProfile as Profile } from '@core/services/character-api.service';

function profile(): Profile {
  return {
    id: 1,
    campaignId: 2,
    slug: 'test',
    name: 'Nom dans l’en-tête',
    type: 'player',
    playerName: 'Camille',
    race: { id: 1, name: 'Elfe' },
    totalLevel: 2,
    proficiencyBonus: 2,
    definition: {},
    classLevels: [
      { position: 1, classId: 1, className: 'Magicien', subclassId: 2, subclassName: 'Évocation' },
    ],
    classSummary: [
      {
        classId: 1,
        classSlug: 'wizard',
        className: 'Magicien',
        level: 2,
        hitDie: 6,
        subclassId: 2,
        subclassSlug: 'evocation',
        subclassName: 'Évocation',
      },
    ],
    hitPoints: {
      maximumValue: 14,
      baseValue: 10,
      constitutionModifier: 2,
      constitutionBonus: 4,
      complete: true,
      missingLevelPositions: [],
    },
    abilities: [
      {
        ability: 'intelligence',
        label: 'Intelligence',
        abbreviation: 'INT',
        baseValue: 16,
        effectiveValue: 18,
        maximumValue: 22,
        modifier: 4,
      },
    ],
    feats: [
      {
        id: 1,
        featId: 1,
        slug: 'don',
        name: 'Don synthétique',
        description: null,
        chosenAbility: 'intelligence',
        abilityIncrease: 2,
        acquiredAtLevel: 2,
      },
    ],
    resources: [{ slug: 'charge', name: 'Charge', maximum: 3, rechargeType: 'long-rest' }],
    features: [
      {
        id: 1,
        slug: 'capacite',
        name: 'Capacité synthétique',
        description: 'Description conservée',
        activationType: 'action',
        visible: true,
        custom: true,
        sourceType: 'class',
        sourceId: 1,
        sourceName: 'Magicien',
        unlockLevel: 2,
        displayOrder: 0,
        resource: { id: 1, slug: 'charge', name: 'Charge' },
      },
    ],
    training: {
      proficiencies: [
        {
          id: 1,
          slug: 'arcana',
          name: 'Arcanes',
          type: 'SKILL',
          grants: [],
          manualGrantIds: [],
          substitutions: [],
        },
      ],
      languages: [{ id: 1, slug: 'elf', name: 'Elfique', sources: [] }],
      expertises: [],
    },
    progressions: [
      {
        id: 1,
        definitionId: 1,
        slug: 'jauge',
        name: 'Jauge narrative',
        description: 'Configuration seulement',
        minimumValue: 0,
        maximumValue: null,
        accentColor: null,
        gainLabel: null,
        spendLabel: null,
        bulkAdjustmentEnabled: false,
        stages: [
          {
            id: 1,
            label: 'Premier seuil',
            minimumValue: 0,
            maximumValue: 3,
            iconUrl: null,
            displayOrder: 0,
          },
        ],
      },
    ],
    actions: [],
  };
}
async function render(data = profile()) {
  const fixture = TestBed.createComponent(CharacterProfile);
  fixture.componentRef.setInput('character', data);
  fixture.componentRef.setInput('canLevelUp', true);
  await fixture.whenStable();
  return fixture;
}
describe('CharacterProfile presentation', () => {
  it('conserves the resolved information without repeating the page identity', async () => {
    const f = await render();
    const text = f.nativeElement.textContent;
    for (const value of [
      'Elfe',
      '14 PV',
      '10 de base',
      '4 de Constitution',
      'Base 16',
      'Maximum 22',
      '+4',
      'Évocation',
      'd6',
      'Don synthétique',
      '+2 Intelligence',
      'Arcanes',
      'Elfique',
      'Jauge narrative',
      'Premier seuil',
      'Charge',
      'Repos long',
      'Capacité synthétique',
      'Description conservée',
    ])
      expect(text).toContain(value);
    expect(text).not.toContain('Nom dans l’en-tête');
    expect(f.nativeElement.querySelectorAll('h3').length).toBe(8);
  });
  it('preserves all five GM action outputs', async () => {
    const f = await render();
    const actions = [
      ['Monter de niveau', 'levelUpRequested'],
      ['Compléter les maîtrises', 'trainingRequested'],
      ['Modifier les PV', 'hitPointHistoryRequested'],
      ['Progressions', 'progressionsRequested'],
      ['Actualiser', 'refreshRequested'],
    ] as const;
    for (const [label, output] of actions) {
      const listener = vi.fn();
      f.componentInstance[output].subscribe(listener);
      const button = Array.from(
        f.nativeElement.querySelectorAll('button') as NodeListOf<HTMLButtonElement>,
      ).find((b) => b.textContent?.trim() === label)!;
      button.click();
      expect(listener).toHaveBeenCalledOnce();
    }
  });
  it('keeps editor and loading restrictions', async () => {
    const f = await render();
    f.componentRef.setInput('editorOpened', true);
    f.componentRef.setInput('loading', true);
    await f.whenStable();
    const buttons = f.nativeElement.querySelectorAll('button');
    expect(buttons.length).toBe(1);
    expect(buttons[0].disabled).toBe(true);
  });
  it('preserves incomplete HP and unavailable level-up states', async () => {
    const data = profile();
    data.hitPoints = {
      ...data.hitPoints,
      maximumValue: null,
      complete: false,
      missingLevelPositions: [2],
    };
    const f = await render(data);
    f.componentRef.setInput('canLevelUp', false);
    await f.whenStable();
    expect(f.nativeElement.textContent).toContain('Niveaux manquants : 2');
    expect(f.nativeElement.textContent).toContain('Compléter les PV');
    expect(f.nativeElement.textContent).not.toContain('Monter de niveau');
  });
  it('keeps legacy information and truthful empty states', async () => {
    const data = profile();
    Object.assign(data, {
      totalLevel: 0,
      classLevels: [],
      classSummary: [],
      definition: { className: 'Classe historique' },
      training: undefined,
      progressions: [],
      feats: [],
      resources: [],
      features: [],
    });
    const f = await render(data);
    for (const value of [
      'Configuration historique',
      'Classe historique',
      'Maîtrises non renseignées',
      'Aucune progression attribuée',
      'Aucun don',
      'Aucune ressource débloquée',
    ])
      expect(f.nativeElement.textContent).toContain(value);
    expect(f.nativeElement.querySelectorAll('button').length).toBe(1);
  });
});

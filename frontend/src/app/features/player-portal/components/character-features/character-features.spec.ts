import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import { CharacterAbilityScore, CharacterFeatSummary, CharacterFeatureSummary } from '@core/services/character-api.service';
import { CharacterFeatures } from './character-features';

describe('Character feature unlock levels', () => {
  it('renders the six server abilities before features, with signed modifiers and only changed bases', () => {
    const fixture = TestBed.createComponent(CharacterFeatures);
    const abilities: CharacterAbilityScore[] = [
      { ability: 'strength', label: 'Force', abbreviation: 'FOR', baseValue: 15, effectiveValue: 16, maximumValue: 20, modifier: 3 },
      { ability: 'dexterity', label: 'Dextérité', abbreviation: 'DEX', baseValue: 10, effectiveValue: 10, maximumValue: 20, modifier: 0 },
      { ability: 'constitution', label: 'Constitution', abbreviation: 'CON', baseValue: 8, effectiveValue: 8, maximumValue: 20, modifier: -1 },
      { ability: 'intelligence', label: 'Intelligence', abbreviation: 'INT', baseValue: 12, effectiveValue: 19, maximumValue: 20, modifier: 4 },
      { ability: 'wisdom', label: 'Sagesse', abbreviation: 'SAG', baseValue: 14, effectiveValue: 14, maximumValue: 20, modifier: 2 },
      { ability: 'charisma', label: 'Charisme', abbreviation: 'CHA', baseValue: 12, effectiveValue: 12, maximumValue: 20, modifier: 1 },
    ];
    fixture.componentRef.setInput('abilities', abilities);
    fixture.componentRef.setInput('features', [{
      id: 1, slug: 'feature', name: 'Capacité existante', description: 'Description', activationType: 'passive', visible: true, custom: false,
      sourceType: 'class', sourceId: 1, sourceName: 'Classe', unlockLevel: 1, displayOrder: 0, resource: null,
    }]);
    fixture.detectChanges();

    const root: HTMLElement = fixture.nativeElement;
    const cards = Array.from(root.querySelectorAll('.character-ability'));
    const texts = (selector: string) => cards.map(card => card.querySelector(selector)?.textContent?.trim() ?? null);
    expect(texts('dt')).toEqual(['Force', 'Dextérité', 'Constitution', 'Intelligence', 'Sagesse', 'Charisme']);
    expect(texts('.character-ability__value')).toEqual(['16', '10', '8', '19', '14', '12']);
    expect(texts('.character-ability__modifier')).toEqual(['+3', '+0', '-1', '+4', '+2', '+1']);
    expect(texts('.character-ability__base')).toEqual(['Base 15', null, null, 'Base 12', null, null]);
    expect(root.querySelector('.character-features')?.firstElementChild?.className).toBe('character-abilities');
    expect(root.querySelector('.character-feature__name')?.textContent).toContain('Capacité existante');

    (root.querySelector('.feature-filter:last-child') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(root.querySelectorAll('.character-ability')).toHaveLength(6);
    expect(root.querySelectorAll('.character-feature')).toHaveLength(1);

    fixture.componentRef.setInput('abilities', abilities.map(ability => ({ ...ability, effectiveValue: ability.baseValue, modifier: 0 })));
    fixture.detectChanges();
    expect(root.querySelectorAll('.character-ability__base')).toHaveLength(0);
    expect(texts('.character-ability__value')[0]).toBe('15');
    expect(texts('.character-ability__modifier')[0]).toBe('+0');
  });

  it('shows the resolved rule level for class, subclass and race features, without displaying the progression placeholder', () => {
    const fixture = TestBed.createComponent(CharacterFeatures);
    const feature = (slug: string, sourceType: CharacterFeatureSummary['sourceType'], unlockLevel: number): CharacterFeatureSummary => ({
      id: 1, slug, name: slug, description: 'Description', activationType: 'passive', visible: true, custom: false,
      sourceType, sourceId: 1, sourceName: sourceType, unlockLevel, displayOrder: 0, resource: null,
    });
    fixture.componentRef.setInput('features', [
      feature('class-one', 'class', 1),
      feature('class-five', 'class', 5),
      feature('subclass-three', 'subclass', 3),
      feature('race-five', 'race', 5),
      feature('progression', 'progression', 1),
    ]);
    fixture.detectChanges();

    const cards = Array.from(fixture.nativeElement.querySelectorAll('.character-feature')) as HTMLElement[];
    expect(cards.map(card => card.querySelector('.character-feature__level')?.textContent?.trim() ?? null))
      .toEqual(['Niveau 1', 'Niveau 5', 'Niveau 3', 'Niveau 5', null]);
  });

  it('shows a feat acquisition level only when it is recorded', () => {
    const fixture = TestBed.createComponent(CharacterFeatures);
    const feat = (id: number, acquiredAtLevel: number | null): CharacterFeatSummary => ({
      id, featId: id, slug: `feat-${id}`, name: `Don ${id}`, description: null,
      chosenAbility: null, abilityIncrease: 0, acquiredAtLevel,
    });
    fixture.componentRef.setInput('features', []);
    fixture.componentRef.setInput('feats', [feat(1, 4), feat(2, null)]);
    fixture.detectChanges();

    const cards = Array.from(fixture.nativeElement.querySelectorAll('.character-feature')) as HTMLElement[];
    expect(cards.map(card => card.querySelector('.character-feature__level')?.textContent?.trim() ?? null))
      .toEqual(['Niveau 4', null]);
  });
});

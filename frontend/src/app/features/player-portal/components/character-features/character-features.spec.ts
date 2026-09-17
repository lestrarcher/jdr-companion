import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import { CharacterFeatSummary, CharacterFeatureSummary } from '@core/services/character-api.service';
import { CharacterFeatures } from './character-features';

describe('Character feature unlock levels', () => {
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

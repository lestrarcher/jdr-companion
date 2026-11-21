import { TestBed } from '@angular/core/testing';
import { expect, it } from 'vitest';
import { CharacterTraining } from '@core/services/character-api.service';
import { CharacterStatistics } from './character-statistics';

it('recalculates automatic feature skills from refreshed server training without stacking or losing another source', () => {
  const fixture = TestBed.createComponent(CharacterStatistics);
  fixture.componentRef.setInput('abilities', []);
  fixture.componentRef.setInput('proficiencyBonus', 3);
  const proficiency = (id: number, slug: string): CharacterTraining['proficiencies'][number] => ({
    id, slug, name: slug, type: 'SKILL', grants: [], manualGrantIds: [], substitutions: [],
  });
  const training = (stealth: boolean): CharacterTraining => ({
    proficiencies: [proficiency(1, 'perception'), ...(stealth ? [proficiency(2, 'stealth')] : [])],
    languages: [], expertises: [],
  });
  const skill = (slug: string) => fixture.componentInstance.skills().find(item => item.slug === slug)!;
  fixture.componentRef.setInput('training', training(false));
  fixture.detectChanges();
  expect(skill('perception').bonus).toBe(3);
  expect(skill('stealth').proficient).toBe(false);

  fixture.componentRef.setInput('training', training(true));
  fixture.detectChanges();
  expect(skill('stealth').proficient).toBe(true);
  expect(skill('stealth').bonus).toBe(3);
  expect(skill('perception').bonus).toBe(3);
  expect(fixture.componentInstance.trainedSkills()).toHaveLength(2);

  fixture.componentRef.setInput('training', training(false));
  fixture.detectChanges();
  expect(skill('stealth').bonus).toBe(0);
  expect(skill('stealth').proficient).toBe(false);
  expect(skill('perception').proficient).toBe(true);
});

import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import { CharacterStatistics } from './character-statistics';

describe('Passive perception (2014)', () => {
  it.each([false, true])('applies Observateur only when owned (%s)', hasObservant => {
    const fixture = TestBed.createComponent(CharacterStatistics);
    fixture.componentRef.setInput('abilities', [{ ability: 'wisdom', modifier: 2 }]);
    fixture.componentRef.setInput('feats', hasObservant ? [{ slug: 'observant' }] : []);
    fixture.detectChanges();
    expect(fixture.componentInstance.basePassivePerception()).toBe(hasObservant ? 17 : 12);
    fixture.destroy();
  });
});

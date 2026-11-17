import { describe, expect, it } from 'vitest';
import { calculateInitiative } from './initiative';

describe('initiative 2014', () => {
  const abilities = { dexterity: 3, wisdom: 2, intelligence: 4, charisma: -1 };
  it('keeps base initiative', () => expect(calculateInitiative(abilities, 3, [], [])).toBe(3));
  it('adds Alert', () => expect(calculateInitiative(abilities, 3, ['alert'], [])).toBe(8));
  it('applies bard half proficiency rounded down', () => expect(calculateInitiative(abilities, 3, [], ['jack-of-all-trades'])).toBe(4));
  it('applies champion half proficiency rounded up', () => expect(calculateInitiative(abilities, 3, [], ['remarkable-athlete'])).toBe(5));
  it('does not stack two half proficiency bonuses', () => expect(calculateInitiative(abilities, 3, [], ['jack-of-all-trades','remarkable-athlete'])).toBe(5));
  it('adds subclass ability modifiers', () => expect(calculateInitiative(abilities, 3, [], ['gloom-stalker-dread-ambusher','war-magic-tactical-wit','swashbuckler-rakish-audacity'])).toBe(8));
  it('does not invent initiative without DEX', () => expect(calculateInitiative({}, 3, [], [])).toBeNull());
});

import { describe, expect, it } from 'vitest';
import { attackCount } from './attack-count';
import type { CharacterClassSummary } from '@core/services/character-api.service';
const cls = (classSlug: string, level: number): CharacterClassSummary => ({ classSlug, level, classId: 1, className: classSlug, hitDie: 10, subclassId: null, subclassSlug: null, subclassName: null });
describe('attackCount', () => {
  it('uses fighter class level', () => { for (const [level, count] of [[4,1],[5,2],[11,3],[20,4]]) expect(attackCount([cls('fighter', level)], [])).toBe(count); });
  it('does not stack multiclass extra attacks', () => expect(attackCount([cls('fighter',5),cls('paladin',5)], [])).toBe(2));
  it('does not use total level', () => expect(attackCount([cls('fighter',4),cls('wizard',10)], [])).toBe(1));
});

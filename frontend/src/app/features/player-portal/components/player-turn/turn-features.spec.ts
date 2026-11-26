import { describe, expect, it } from 'vitest';
import type { CharacterFeatureSummary } from '@core/services/character-api.service';
import { groupTurnFeatures } from './turn-features';

function feature(id: number, activationType: string, visible = true): CharacterFeatureSummary {
  return { id, slug: `feature-${id}`, name: `Feature ${id}`, description: null, activationType, visible, custom: false, sourceType: 'progression', sourceId: 1, sourceName: 'Test', unlockLevel: 1, displayOrder: 0, resource: null };
}
describe('groupTurnFeatures', () => {
  it('groups all supported active types and excludes passive, unknown and hidden features', () => {
    const result = groupTurnFeatures([feature(1,'action'), feature(2,'bonus_action'), feature(3,'reaction'), feature(4,'free_action'), feature(5,'special'), feature(6,'passive'), feature(7,'unknown'), feature(8,'action',false), feature(1,'action')]);
    expect(Object.values(result).map(group => group.map(f => f.id))).toEqual([[1],[2],[3],[4],[5]]);
  });
});

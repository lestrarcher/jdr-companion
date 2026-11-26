import type { CharacterFeatureSummary } from '@core/services/character-api.service';

const sections: Record<string, string> = {
  action: 'action', bonus_action: 'bonus', reaction: 'reaction',
  free_action: 'free', special: 'special',
};

export function groupTurnFeatures(features: readonly CharacterFeatureSummary[]): Record<string, CharacterFeatureSummary[]> {
  const result: Record<string, CharacterFeatureSummary[]> = { action: [], bonus: [], reaction: [], free: [], special: [] };
  const seen = new Set<number>();
  for (const feature of features) {
    const section = Object.prototype.hasOwnProperty.call(sections, feature.activationType) ? sections[feature.activationType] : undefined;
    if (!feature.visible || !section || seen.has(feature.id)) continue;
    seen.add(feature.id);
    result[section].push(feature);
  }
  for (const group of Object.values(result)) group.sort((a, b) => a.name.localeCompare(b.name, 'fr'));
  return result;
}

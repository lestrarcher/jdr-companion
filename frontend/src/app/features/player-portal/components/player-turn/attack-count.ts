import type { CharacterClassSummary, CharacterFeatureSummary } from '@core/services/character-api.service';

function normalize(value: string): string {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-');
}
export function hasThirstingBlade(features: readonly CharacterFeatureSummary[]): boolean {
  return features.some(f => /(?:thirsting-blade|lame-assoiffee)/.test(normalize(f.slug) + ' ' + normalize(f.name)));
}
export function attackCount(classes: readonly CharacterClassSummary[], features: readonly CharacterFeatureSummary[]): number {
  let count = 1;
  for (const c of classes) {
    const cls = normalize(c.classSlug || c.className);
    if (/^(fighter|guerrier)$/.test(cls)) count = Math.max(count, c.level >= 20 ? 4 : c.level >= 11 ? 3 : c.level >= 5 ? 2 : 1);
    else if (/^(barbarian|barbare|monk|moine|paladin|ranger|rodeur)$/.test(cls) && c.level >= 5) count = Math.max(count, 2);
  }
  // Other subclasses and acquired features: use the actual feature, not description text.
  if (features.some(f => /(?:extra-attack|attaque-supplementaire)/.test(normalize(f.slug) + ' ' + normalize(f.name)))) count = Math.max(count, 2);
  return count;
}

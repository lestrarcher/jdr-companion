/** Permanent D&D 5e (2014) initiative modifiers. */
export function calculateInitiative(
  modifiers: Partial<Record<'dexterity' | 'wisdom' | 'intelligence' | 'charisma', number>>,
  proficiencyBonus: number,
  featSlugs: readonly string[],
  featureSlugs: readonly string[],
): number | null {
  if (modifiers.dexterity === undefined) return null;
  const feats = new Set(featSlugs);
  const features = new Set(featureSlugs);
  const half = Math.max(
    features.has('jack-of-all-trades') ? Math.floor(proficiencyBonus / 2) : 0,
    features.has('remarkable-athlete') ? Math.ceil(proficiencyBonus / 2) : 0,
  );
  return modifiers.dexterity
    + (feats.has('alert') ? 5 : 0)
    + half
    + (features.has('gloom-stalker-dread-ambusher') ? (modifiers.wisdom ?? 0) : 0)
    + (features.has('war-magic-tactical-wit') ? (modifiers.intelligence ?? 0) : 0)
    + (features.has('swashbuckler-rakish-audacity') ? (modifiers.charisma ?? 0) : 0);
}

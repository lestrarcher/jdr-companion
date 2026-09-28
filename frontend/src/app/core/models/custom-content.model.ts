export type CustomSourceType = 'class' | 'subclass' | 'race' | 'feat';
export type CustomScalingAbility = 'strength' | 'dexterity' | 'constitution' | 'intelligence' | 'wisdom' | 'charisma';

export interface CustomReferenceSummary {
  id: number;
  name: string;
  slug: string;
  origin: 'OFFICIAL' | 'CUSTOM';
}

export interface CustomReferenceSource extends CustomReferenceSummary {
  type: CustomSourceType | 'progression';
}

export interface CustomResource {
  id: number;
  name: string;
  description: string | null;
  slug: string;
  rechargeType: 'none' | 'short-rest' | 'long-rest';
  maximumType: 'fixed' | 'proficiency-bonus' | 'ability-modifier';
  baseMaximum: number;
  multiplier: number;
  minimumMaximum: number;
  scalingAbility: CustomScalingAbility | null;
}

export interface CustomFeature {
  id: number;
  name: string;
  description: string | null;
  slug: string;
  resourceDefinition: CustomReferenceSummary | null;
}

export interface CustomFeatureRule {
  id: number;
  featureDefinition: CustomReferenceSummary;
  source: CustomReferenceSource;
  unlockLevel: number | null;
  progressionThreshold: number | null;
}

export interface CustomResourceRule {
  id: number;
  resourceDefinition: CustomReferenceSummary;
  source: CustomReferenceSummary & { type: CustomSourceType };
  unlockLevel: number;
  maximumBonus: number;
}

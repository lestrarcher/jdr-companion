export type ResourceCategory =
  | 'class-feature'
  | 'spell-slot'
  | 'item'
  | 'custom';

export type ResourceResetPeriod =
  | 'short-rest'
  | 'long-rest'
  | 'dawn'
  | 'manual'
  | 'never';

export interface HitPointState {
  current: number;
  maximum: number;
  temporary: number;
}

export interface ProgressionStateIndicator {
  label: string;
  minimumValue: number;
  maximumValue?: number;
  iconUrl: string;
}

export interface ProgressionBulkAdjustment {
  gainLabel: string;
  spendLabel: string;
}

export interface ProgressionLinkedResource {
  resourceId: string;
  label: string;
}

export interface CharacterProgression {
  id: string;
  name: string;
  currentValue: number;
  minimumValue: number;
  maximumValue?: number;

  accentColor?: string;

  states?: ProgressionStateIndicator[];
  bulkAdjustment?: ProgressionBulkAdjustment;
  linkedResource?: ProgressionLinkedResource;
}

export interface ResourceUnlockCondition {
  progressionId: string;
  minimumValue: number;
}

export interface CharacterResource {
  id: string;
  name: string;
  shortName?: string;
  category: ResourceCategory;
  resetPeriod: ResourceResetPeriod;
  notes?: string;
  notesEditable?: boolean;
  allowManualIncrease?: boolean;
  currentValue: number;
  maximumValue: number;
  level?: number;
  displayOrder: number;
  unlockCondition?: ResourceUnlockCondition;
  hiddenFromTracker?: boolean;

  storedValues?: number[];
  storedValuesConfig?: StoredValuesConfig;
}

export interface Character {
  id: string;
  name: string;
  type: CharacterType;

  className: string;
  level: number;

  portraitUrl?: string;

  hitPoints: HitPointState;
  hitDice: HitDicePool[];
  progressions?: CharacterProgression[];
  resources: CharacterResource[];
}

export type CharacterType = 'pc' | 'npc';

export type HitDie =
  | 'd6'
  | 'd8'
  | 'd10'
  | 'd12';

export interface HitDicePool {
  id: string;
  die: HitDie;
  current: number;
  maximum: number;
}

export interface StoredValuesConfig {
  requiredCount: number;
  minimumValue: number;
  maximumValue: number;
}

export interface ProgressionStateIndicator {
  label: string;
  minimumValue: number;
  maximumValue?: number;
}


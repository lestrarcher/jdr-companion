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

export interface CharacterProgression {
  id: string;
  name: string;
  currentValue: number;
  minimumValue: number;
  maximumValue?: number;
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
  unlockCondition?: ResourceUnlockCondition;
  allowManualIncrease?: boolean;
  currentValue: number;
  maximumValue: number;

  level?: number;
  displayOrder: number;
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

import type { Spell } from './spell.model';
import type { LevelUpSpellPolicyPreview } from '../services/character-api.service';

export type InitialSpell = Pick<Spell, 'id' | 'name' | 'level' | 'school'> & {
  castingTime?: string | null;
  range?: string | null;
  duration?: string | null;
  description?: string | null;
  higherLevels?: string | null;
  materialDescription?: string | null;
  verbal?: boolean;
  somatic?: boolean;
  material?: boolean;
  concentration?: boolean;
  ritual?: boolean;
};
export interface InitializationOptions extends Pick<LevelUpSpellPolicyPreview,
  'policyId' | 'learningStyle' | 'limits' | 'used' | 'remaining' | 'blocked' | 'acquisitions' | 'automatic'> {
  formatVersion?: number;
  status: string;
  limitations: string[];
  available: InitialSpell[];
  choices?: Array<{ ruleId: number; key: string; quota: string; result: string; remaining: number; available: InitialSpell[] }>;
}
export interface SpellInitializationPreview {
  characterId: number;
  classes: Array<{
    classId: number; className: string; classLevel: number; status: string; policyIds: number[];
    policies: Array<{ id: number; version: number; formatVersion: number }>;
    options: InitializationOptions | null;
    restrictions: Array<{ ruleId: number; key: string; parameters: { opportunities: number[]; schools: string[]; minimumSpellLevel: number; maxSpellLevels: number[] } }>;
  }>;
}
export interface SpellInitializationPayload {
  policyId: number;
  reason: string;
  choices: Array<{ spellId: number; ruleId: number | null }>;
}
export interface SpellInitializationResult {
  acquisitionIds: number[];
  createdAcquisitionIds: number[];
  replayedAcquisitionIds: number[];
}

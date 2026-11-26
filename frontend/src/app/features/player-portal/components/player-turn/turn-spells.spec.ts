import { describe, expect, it } from 'vitest';
import type { PlayerSpell } from '@core/services/character-session-state-api.service';
import { groupTurnSpells, turnSpellCategory } from './turn-spells';
const spell = (overrides: Partial<PlayerSpell> = {}): PlayerSpell => ({
  spellId: 1, name: 'Bouclier', level: 1, school: 'abjuration', source: 'Magicien', sourceType: 'class',
  castingAbility: 'intelligence', spellSaveDc: null, spellAttackBonus: null, known: true, inSpellbook: false, prepared: null,
  details: { description: '', higherLevels: null, castingTime: '1 réaction, lorsque vous êtes touché', range: '', duration: '', verbal: true, somatic: true, material: false, materialDescription: null, concentration: false, ritual: false }, ...overrides,
});
describe('turn spells', () => {
  it('classifies native casting times', () => {
    expect(['1 action', '1 action bonus', '1 réaction, lorsque...', '1 minute', '1 bonus action', undefined].map(turnSpellCategory)).toEqual(['action','bonus','reaction',null,'bonus',null]);
  });
  it('deduplicates and prefers a usable source', () => {
    const available = spell({key:'b', castingAvailability:{sourceKey:'b',policyId:null,status:'available',reason:null,methods:[{kind:'standard-slot',castingLevel:1,poolSlug:null,cost:1,status:'available',reason:null}]}});
    expect(groupTurnSpells([spell(),available], 'reaction')).toEqual([available]);
  });
  it('excludes unprepared spellbook entries and keeps known spells without slots', () => {
    expect(groupTurnSpells([spell({inSpellbook:true,prepared:false}),spell({spellId:2})], 'reaction').map(s=>s.spellId)).toEqual([2]);
  });
});

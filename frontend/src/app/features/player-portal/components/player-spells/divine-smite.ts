import type { PlayerSpell } from '@core/services/character-session-state-api.service';
import type { SpellCastingAvailability } from '@core/models/spell-cast.model';
import type { SpellSlotGroup } from '../spell-slot-bar/spell-slot-bar';

export const DIVINE_SMITE_KEY = 'virtual:divine-smite';
export const isDivineSmite = (spell: PlayerSpell | null | undefined): boolean => spell?.key === DIVINE_SMITE_KEY;
export const divineSmiteDice = (level: number): number => Math.min(5, level + 1);
export function divineSmiteSpell(groups: readonly SpellSlotGroup[]): PlayerSpell {
  const methods: SpellCastingAvailability['methods'] = groups.filter(g => g.level !== null && g.level >= 1 && g.level <= 9).map(g => ({
    kind: g.pact ? 'pact-slot' : 'standard-slot', castingLevel: g.level, poolSlug: g.id, cost: 1,
    status: g.current === null ? 'unknown' : g.current > 0 ? 'available' : 'unavailable', reason: null,
  }));
  return { key: DIVINE_SMITE_KEY, spellId: -1, name: 'Châtiment divin', level: 1, school: 'evocation', source: 'Paladin · Capacité', sourceType: 'feature',
    castingAbility: null, spellSaveDc: null, spellAttackBonus: null, known: true, inSpellbook: false, prepared: null,
    details: { description: 'Lorsque vous touchez avec une attaque d’arme de corps à corps, dépensez un emplacement pour ajouter 2d8 dégâts radiants (3d8 contre un fiélon ou mort-vivant). Cette capacité ne constitue pas un sort.', higherLevels: 'Ajoutez 1d8 par niveau supérieur au premier, jusqu’à 5d8 (6d8 contre un fiélon ou mort-vivant).', castingTime: 'Lors d’une attaque réussie', range: 'Cible de l’attaque', duration: 'Instantanée', verbal: false, somatic: false, material: false, materialDescription: null, concentration: false, ritual: false },
    castingAvailability: { sourceKey: DIVINE_SMITE_KEY, policyId: null, status: methods.some(m => m.status === 'available') ? 'available' : 'unavailable', reason: null, methods } };
}

import type { PlayerSpell } from '@core/services/character-session-state-api.service';
import { availableSpellCastMethods } from '@core/models/spell-cast.model';

export type TurnSpellCategory = 'action' | 'bonus' | 'reaction';
export function turnSpellCategory(time: string | null | undefined): TurnSpellCategory | null {
  const normalized = (time ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toLowerCase();
  if (/^(?:1|une|one)\s+(?:action bonus|bonus action)\b/.test(normalized)) return 'bonus';
  if (/^(?:1|une|one)\s+reaction\b/.test(normalized)) return 'reaction';
  if (/^(?:1|une|one)\s+action\b/.test(normalized)) return 'action';
  return null;
}
export function combatCastAvailable(spell: PlayerSpell): boolean {
  return availableSpellCastMethods(spell).some(method => method.kind !== 'ritual');
}
export function groupTurnSpells(spells: readonly PlayerSpell[], category: TurnSpellCategory): PlayerSpell[] {
  const groups = new Map<number, PlayerSpell>();
  for (const spell of spells) {
    if (spell.itemGrant?.active === false || turnSpellCategory(spell.details?.castingTime) !== category) continue;
    if (!(spell.known && (!spell.inSpellbook || spell.level === 0)) && !spell.prepared && !spell.alwaysPrepared && !spell.itemGrant?.active && !combatCastAvailable(spell)) continue;
    const existing = groups.get(spell.spellId);
    if (!existing || (!combatCastAvailable(existing) && combatCastAvailable(spell))) groups.set(spell.spellId, spell);
  }
  return [...groups.values()].sort((a,b) => (a.level ?? 99) - (b.level ?? 99) || a.name.localeCompare(b.name, 'fr'));
}

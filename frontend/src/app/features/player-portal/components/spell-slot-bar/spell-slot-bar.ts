import { Component, input, output } from '@angular/core';
import { CharacterResource } from '@core/models/character.model';

export interface SpellSlotGroup {
  id: string; level: number | null; pact: boolean; maximum: number | null; current: number | null; cells: number[];
}

/** Projection only: maxima and counter validity come from the server mapping. */
export function spellSlotGroups(resources: readonly CharacterResource[]): SpellSlotGroup[] {
  return resources.flatMap(resource => {
    if (!resource.slotPool || resources.filter(row => row.id === resource.id).length !== 1) return [];
    if (!/^(spell-slot-[1-9]|warlock-pact-slots)$/.test(resource.id) || resource.maximumValue === 0) return [];
    const maximum = Number.isSafeInteger(resource.maximumValue) && resource.maximumValue > 0 ? resource.maximumValue : null;
    const current = resource.slotPool.counterKnown && maximum !== null && Number.isInteger(resource.currentValue)
      && resource.currentValue >= 0 && resource.currentValue <= maximum ? resource.currentValue : null;
    return [{ id: resource.id, level: resource.slotPool.level, pact: resource.id === 'warlock-pact-slots',
      maximum, current, cells: maximum !== null && maximum <= 100 ? Array.from({length: maximum}, (_, i) => i) : [] }];
  }).sort((a,b) => Number(a.pact) - Number(b.pact) || (a.level ?? 10) - (b.level ?? 10));
}

@Component({
  selector: 'app-spell-slot-bar',
  templateUrl: './spell-slot-bar.html',
  styleUrl: './spell-slot-bar.scss',
})
export class SpellSlotBar {
  readonly groups = input.required<SpellSlotGroup[]>();
  readonly showCantrips = input(false);
  readonly selectedPool = input<string | null>(null);
  readonly filterSelected = output<string>();
  selectable(group: SpellSlotGroup): boolean { return (group.current ?? 0) > 0 && group.level !== null && group.level >= 1 && group.level <= 9; }
  choose(group: SpellSlotGroup): void { if (this.selectable(group)) this.filterSelected.emit(group.id); }
  roman(level: number | null): string { return ['0','I','II','III','IV','V','VI','VII','VIII','IX'][level ?? -1] ?? '?'; }
  label(group: SpellSlotGroup): string {
    return `${group.pact ? 'Magie de pacte' : 'Emplacements'} — niveau ${group.level ?? 'inconnu'} : ${group.current ?? 'quantité inconnue'} sur ${group.maximum ?? 'maximum inconnu'}${group.pact ? ', récupération au repos court' : ''}`;
  }
}

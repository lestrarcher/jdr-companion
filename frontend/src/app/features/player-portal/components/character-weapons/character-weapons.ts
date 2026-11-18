import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { CharacterWeapon } from '@core/models/character-weapon.model';

@Component({selector:'app-character-weapons', standalone:true, templateUrl:'./character-weapons.html', styleUrl:'./character-weapons.scss', changeDetection:ChangeDetectionStrategy.OnPush})
export class CharacterWeapons {
  readonly weapons = input.required<CharacterWeapon[]>();

  protected visibleNotes(notes: string[]): string[] {
    return notes
      .map(note => note.replace(/\s*\(?meilleur mod(?:ificateur)?\s+FOR\s*\/\s*DEX\)?/gi, '').trim())
      .filter(Boolean);
  }
}

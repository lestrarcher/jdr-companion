import { Component, input, output } from '@angular/core';

import { CharacterApiResponse } from '@core/services/character-api.service';

import { SessionCharacterView } from '../session-character.models';

@Component({
  selector: 'app-available-characters',
  imports: [],
  templateUrl: './available-characters.html',
  styleUrl: './available-characters.scss',
})
export class AvailableCharacters {
  readonly characters = input.required<SessionCharacterView[]>();
  readonly changingCharacterId = input<number | null>(null);

  readonly characterAdded = output<SessionCharacterView>();

  protected characterInitial(name: string): string {
    return name.trim().charAt(0).toUpperCase();
  }

  protected characterSummary(character: CharacterApiResponse): string {
    const classes = character.classLevels.reduce<Record<string, number>>(
      (summary, level) => {
        summary[level.className] =
          (summary[level.className] ?? 0) + 1;

        return summary;
      },
      {},
    );

    return Object.entries(classes)
      .map(([name, level]) => `${name} ${level}`)
      .join(' / ');
  }

  protected isChanging(characterId: number): boolean {
    return this.changingCharacterId() === characterId;
  }
}

import { Component, input, output } from '@angular/core';
import { CharacterApiResponse, CharacterProfile as CharacterProfileModel } from '@core/services/character-api.service';
import { AbilityKey } from '@core/services/dnd-reference-api.service';
import { CharacterStatistics } from '../../../player-portal/components/character-statistics/character-statistics';

@Component({
  selector: 'app-character-sheet',
  standalone: true,
  imports: [CharacterStatistics],
  templateUrl: './character-sheet.html',
  styleUrl: './character-sheet.scss',
})
export class CharacterSheet {
  readonly character = input.required<CharacterProfileModel>();
  readonly canLevelUp = input(false);
  readonly loading = input(false);
  readonly editorOpened = input(false);
  readonly progressionsRequested = output<void>();
  readonly trainingRequested = output<void>();
  readonly refreshRequested = output<void>();
  readonly levelUpRequested = output<void>();
  readonly hitPointHistoryRequested = output<void>();

  protected progressionLabel(character: CharacterApiResponse): string {
    if (character.totalLevel === 0 || character.classLevels.length === 0) {
      return this.legacyProgressionLabel(character);
    }

    const classes = new Map<
      number,
      { name: string; level: number; subclass: string | null }
    >();

    for (const classLevel of character.classLevels) {
      const existing = classes.get(classLevel.classId);

      if (existing) {
        ++existing.level;

        if (classLevel.subclassName) {
          existing.subclass = classLevel.subclassName;
        }

        continue;
      }

      classes.set(classLevel.classId, {
        name: classLevel.className,
        level: 1,
        subclass: classLevel.subclassName,
      });
    }

    return [...classes.values()]
      .map(entry => {
        const classLabel = `${entry.name} ${entry.level}`;

        return entry.subclass
          ? `${classLabel} · ${entry.subclass}`
          : classLabel;
      })
      .join(' / ');
  }

  protected isStructured(character: CharacterApiResponse): boolean {
    return character.totalLevel > 0 && character.classLevels.length > 0;
  }

  protected abilityLabel(
    character: CharacterProfileModel,
    ability: AbilityKey | null,
  ): string | null {
    if (!ability) {
      return null;
    }

    return character.abilities.find(
      entry => entry.ability === ability,
    )?.label ?? ability;
  }

  private legacyProgressionLabel(
    character: CharacterApiResponse,
  ): string {
    const className = this.readString(
      character.definition,
      ['className', 'classLabel', 'class'],
    );

    const level = character.definition['level'];

    if (className && typeof level === 'number') {
      return `${className} ${level}`;
    }

    return className ?? 'Configuration historique';
  }

  private readString(
    definition: Record<string, unknown>,
    keys: string[],
  ): string | null {
    for (const key of keys) {
      const value = definition[key];

      if (typeof value === 'string' && value.trim() !== '') {
        return value;
      }
    }

    return null;
  }
}

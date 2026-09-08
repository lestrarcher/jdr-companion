import { Component, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  CharacterApiResponse,
} from '@core/services/character-api.service';

type CharacterFilter = 'all' | 'player' | 'npc';

@Component({
  selector: 'app-character-roster',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './character-roster.html',
  styleUrl: './character-roster.scss',
})
export class CharacterRoster {
  readonly characters = input.required<CharacterApiResponse[]>();
  readonly selectedCharacterId = input<number | null>(null);
  readonly characterSelected = output<CharacterApiResponse>();

  protected readonly filter = signal<CharacterFilter>('all');
  protected search = '';

  protected filteredCharacters(): CharacterApiResponse[] {
    const filter = this.filter();
    const search = this.normalizeSearch(this.search);

    return this.characters().filter(character => {
      if (filter !== 'all' && character.type !== filter) {
        return false;
      }

      if (!search) {
        return true;
      }

      return this.normalizeSearch(
        `${character.name} ${character.playerName ?? ''} ${this.progressionLabel(character)}`,
      ).includes(search);
    });
  }

  protected setFilter(filter: CharacterFilter): void {
    this.filter.set(filter);
  }

  protected countByType(type: CharacterFilter): number {
    if (type === 'all') {
      return this.characters().length;
    }

    return this.characters().filter(
      character => character.type === type,
    ).length;
  }

  protected selectCharacter(character: CharacterApiResponse): void {
    this.characterSelected.emit(character);
  }

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

  private normalizeSearch(value: string): string {
    return value
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .trim();
  }
}

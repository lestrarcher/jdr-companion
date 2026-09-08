import { Component, input, output } from '@angular/core';
import {
  CharacterApiResponse,
  CharacterProfile as CharacterProfileModel,
} from '@core/services/character-api.service';
import { AbilityKey } from '@core/services/dnd-reference-api.service';

@Component({
  selector: 'app-character-profile',
  standalone: true,
  templateUrl: './character-profile.html',
  styleUrl: './character-profile.scss',
})
export class CharacterProfile {
  readonly character = input.required<CharacterProfileModel>();
  readonly canLevelUp = input(false);
  readonly loading = input(false);
  readonly editorOpened = input(false);

  readonly refreshRequested = output<void>();
  readonly levelUpRequested = output<void>();
  readonly hitPointHistoryRequested = output<void>();

  protected typeLabel(character: CharacterApiResponse): string {
    return character.type === 'player'
      ? 'Personnage joueur'
      : 'Personnage non-joueur';
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

  protected modifierLabel(modifier: number): string {
    return modifier >= 0 ? `+${modifier}` : `${modifier}`;
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

  protected rechargeLabel(rechargeType: string): string {
    return {
      'short-rest': 'Repos court ou long',
      short_rest: 'Repos court ou long',
      'long-rest': 'Repos long',
      long_rest: 'Repos long',
      dawn: 'À l’aube',
      manual: 'Recharge manuelle',
      none: 'Aucune recharge',
      never: 'Jamais',
    }[rechargeType] ?? rechargeType.replaceAll(/[-_]/g, ' ');
  }

  protected activationLabel(activationType: string): string {
    return {
      passive: 'Passif',
      action: 'Action',
      bonus_action: 'Action bonus',
      'bonus-action': 'Action bonus',
      reaction: 'Réaction',
      free_action: 'Action libre',
      'free-action': 'Action libre',
      special: 'Spécial',
    }[activationType] ?? activationType.replaceAll(/[-_]/g, ' ');
  }

  protected featureSourceLabel(sourceType: string): string {
    return {
      class: 'Classe',
      subclass: 'Sous-classe',
      race: 'Race',
      feat: 'Don',
    }[sourceType] ?? sourceType;
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

import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import {
  CharacterApiResponse,
  CharacterApiService,
} from '@core/services/character-api.service';

type CharacterFilter = 'all' | 'player' | 'npc';

@Component({
  selector: 'app-campaign-characters',
  standalone: true,
  imports: [RouterLink],
  templateUrl: './campaign-characters.html',
  styleUrl: './campaign-characters.scss',
})
export class CampaignCharacters {
  private readonly route = inject(ActivatedRoute);
  private readonly characterApi = inject(CharacterApiService);

  protected readonly campaignId = Number(
    this.route.snapshot.paramMap.get('campaignId'),
  );

  protected readonly characters = signal<CharacterApiResponse[]>([]);
  protected readonly filter = signal<CharacterFilter>('all');
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  public constructor() {
    if (
      !Number.isInteger(this.campaignId)
      || this.campaignId < 1
    ) {
      this.loading.set(false);
      this.error.set('Identifiant de campagne invalide.');

      return;
    }

    this.loadCharacters();
  }

  protected filteredCharacters(): CharacterApiResponse[] {
    const filter = this.filter();

    if (filter === 'all') {
      return this.characters();
    }

    return this.characters().filter(
      (character) => character.type === filter,
    );
  }

  protected setFilter(filter: CharacterFilter): void {
    this.filter.set(filter);
  }

  protected countByType(type: CharacterFilter): number {
    if (type === 'all') {
      return this.characters().length;
    }

    return this.characters().filter(
      (character) => character.type === type,
    ).length;
  }

  protected typeLabel(character: CharacterApiResponse): string {
    return character.type === 'player'
      ? 'Personnage joueur'
      : 'Personnage non-joueur';
  }

  protected progressionLabel(
    character: CharacterApiResponse,
  ): string {
    if (
      character.totalLevel > 0
      && character.classLevels.length > 0
    ) {
      const classes = new Map<
        number,
        {
          name: string;
          level: number;
          subclass: string | null;
        }
      >();

      for (const classLevel of character.classLevels) {
        const existing = classes.get(classLevel.classId);

        if (existing) {
          existing.level += 1;

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
        .map((entry) => {
          const classLabel = `${entry.name} ${entry.level}`;

          return entry.subclass
            ? `${classLabel} · ${entry.subclass}`
            : classLabel;
        })
        .join(' / ');
    }

    return this.legacyProgressionLabel(character);
  }

  protected isStructured(character: CharacterApiResponse): boolean {
    return character.totalLevel > 0
      && character.classLevels.length > 0;
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

    if (className) {
      return className;
    }

    return 'Configuration historique';
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

  private loadCharacters(): void {
    this.loading.set(true);
    this.error.set(null);

    this.characterApi.list(this.campaignId)
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (characters) => {
          this.characters.set(
            [...characters].sort((first, second) =>
              first.name.localeCompare(second.name, 'fr'),
            ),
          );
        },
        error: () => {
          this.error.set(
            'Impossible de charger les personnages de cette campagne.',
          );
        },
      });
  }
}

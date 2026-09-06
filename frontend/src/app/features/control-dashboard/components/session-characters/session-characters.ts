import {
  Component,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';

import {
  forkJoin,
  of,
} from 'rxjs';

import {
  catchError,
  finalize,
} from 'rxjs/operators';

import {
  ImportedCharacterResult,
} from '@core/services/campaign-bootstrap.service';

import {
  CharacterSessionStateApiService,
} from '@core/services/character-session-state-api.service';

import {
  MagicItemApiService,
} from '@core/services/magic-item-api.service';

import {
  CharacterMagicItemInventoryResponse,
  EffectiveAbilityResponse,
  MagicItemResponse,
} from '@core/services/public-character-magic-item-api.service';

import {
  RestRequestApiResponse,
} from '@core/services/rest-request-api.service';

export interface RestRequestResolution {
  requestId: number;
  approved: boolean;
}

interface HitPointState {
  current: number;
  temporary: number;
}

@Component({
  selector: 'app-session-characters',
  imports: [],
  templateUrl: './session-characters.html',
  styleUrl: './session-characters.scss',
})
export class SessionCharacters {
  private readonly characterSessionStateApi =
    inject(CharacterSessionStateApiService);

  private readonly magicItemApi =
    inject(MagicItemApiService);

  readonly campaignId =
    input.required<number>();

  readonly sessionId =
    input.required<number>();

  readonly characters =
    input.required<ImportedCharacterResult[]>();

  readonly initializationRunning =
    input(false);

  readonly initializationError =
    input<string | null>(null);

  readonly restRequests =
    input.required<RestRequestApiResponse[]>();

  readonly resolvingRestRequestId =
    input<number | null>(null);

  readonly restRequestError =
    input<string | null>(null);

  readonly charactersInitialized =
    output<void>();

  readonly restRequestResolved =
    output<RestRequestResolution>();

  protected readonly remoteHitPoints =
    signal<Record<number, HitPointState>>({});

  protected readonly refreshingHitPoints =
    signal(false);

  protected readonly characterActionError =
    signal<string | null>(null);

  protected readonly catalog =
    signal<MagicItemResponse[]>([]);

  protected readonly catalogLoading =
    signal(false);

  protected readonly selectedCharacter =
    signal<ImportedCharacterResult | null>(null);

  protected readonly selectedMagicItemId =
    signal<number | null>(null);

  protected readonly assigningItem =
    signal(false);

  protected readonly assignmentFeedback =
    signal<string | null>(null);

  protected readonly statisticsCharacter =
    signal<ImportedCharacterResult | null>(null);

  protected readonly statisticsInventory =
    signal<CharacterMagicItemInventoryResponse | null>(
      null,
    );

  protected readonly statisticsLoading =
    signal(false);

  protected readonly hasCharacters =
    computed(
      () => this.characters().length > 0,
    );

  protected readonly selectedMagicItem =
    computed(() => {
      const selectedId =
        this.selectedMagicItemId();

      if (selectedId === null) {
        return null;
      }

      return (
        this.catalog().find(
          (magicItem) =>
            magicItem.id === selectedId,
        ) ?? null
      );
    });

  protected initializeCharacters(): void {
    if (this.initializationRunning()) {
      return;
    }

    this.charactersInitialized.emit();
  }

  protected resolveRestRequest(
    requestId: number,
    approved: boolean,
  ): void {
    if (
      this.resolvingRestRequestId() !==
      null
    ) {
      return;
    }

    this.restRequestResolved.emit({
      requestId,
      approved,
    });
  }

  protected playerPortalUrl(
    accessToken: string | null,
  ): string | null {
    if (!accessToken) {
      return null;
    }

    return [
      '',
      'campaigns',
      this.campaignId(),
      'sessions',
      this.sessionId(),
      'player',
      accessToken,
    ].join('/');
  }

  protected characterInitial(
    characterName: string,
  ): string {
    return characterName
      .trim()
      .charAt(0)
      .toUpperCase();
  }

  protected restTypeLabel(
    type: 'short-rest' | 'long-rest',
  ): string {
    return type === 'short-rest'
      ? 'Repos court'
      : 'Repos long';
  }

  protected currentHitPoints(
    character: ImportedCharacterResult,
  ): number {
    return (
      this.remoteHitPoints()[
        character.characterId
      ]?.current
      ?? character.currentHitPoints
    );
  }

  protected temporaryHitPoints(
    character: ImportedCharacterResult,
  ): number {
    return (
      this.remoteHitPoints()[
        character.characterId
      ]?.temporary
      ?? character.temporaryHitPoints
    );
  }

  protected hitPointPercentage(
    character: ImportedCharacterResult,
  ): number {
    if (character.maximumHitPoints <= 0) {
      return 0;
    }

    return Math.min(
      100,
      Math.max(
        0,
        (
          this.currentHitPoints(character)
          / character.maximumHitPoints
        ) * 100,
      ),
    );
  }

  protected hitPointTone(
    character: ImportedCharacterResult,
  ): 'healthy' | 'wounded' | 'critical' {
    const percentage =
      this.hitPointPercentage(character);

    if (percentage <= 25) {
      return 'critical';
    }

    if (percentage <= 50) {
      return 'wounded';
    }

    return 'healthy';
  }

  protected refreshHitPoints(): void {
    const charactersWithLinks =
      this.characters().filter(
        (
          character,
        ): character is ImportedCharacterResult & {
          accessToken: string;
        } =>
          typeof character.accessToken ===
            'string'
          && character.accessToken.length > 0,
      );

    if (
      this.refreshingHitPoints()
      || charactersWithLinks.length === 0
    ) {
      return;
    }

    this.characterActionError.set(null);
    this.refreshingHitPoints.set(true);

    forkJoin(
      charactersWithLinks.map((character) =>
        this.characterSessionStateApi
          .getByAccessToken(
            character.accessToken,
          )
          .pipe(
            catchError(() => of(null)),
          ),
      ),
    )
      .pipe(
        finalize(() => {
          this.refreshingHitPoints.set(false);
        }),
      )
      .subscribe((states) => {
        const updatedHitPoints = {
          ...this.remoteHitPoints(),
        };

        states.forEach((state) => {
          if (!state) {
            return;
          }

          updatedHitPoints[
            state.character.id
          ] = {
            current:
              state.state.hitPoints.current,

            temporary:
              state.state.hitPoints.temporary,
          };
        });

        this.remoteHitPoints.set(
          updatedHitPoints,
        );
      });
  }

  protected openItemAssignment(
    character: ImportedCharacterResult,
  ): void {
    this.selectedCharacter.set(character);
    this.selectedMagicItemId.set(null);
    this.assignmentFeedback.set(null);
    this.characterActionError.set(null);

    if (this.catalog().length === 0) {
      this.loadCatalog();
    }
  }

  protected closeItemAssignment(): void {
    if (this.assigningItem()) {
      return;
    }

    this.selectedCharacter.set(null);
    this.selectedMagicItemId.set(null);
    this.assignmentFeedback.set(null);
  }

  protected selectMagicItem(
    event: Event,
  ): void {
    const value = (
      event.target as HTMLSelectElement
    ).value;

    this.selectedMagicItemId.set(
      value ? Number(value) : null,
    );
  }

  protected assignSelectedItem(): void {
    const character =
      this.selectedCharacter();

    const magicItemId =
      this.selectedMagicItemId();

    if (
      !character
      || magicItemId === null
      || this.assigningItem()
    ) {
      return;
    }

    this.assigningItem.set(true);
    this.assignmentFeedback.set(null);
    this.characterActionError.set(null);

    this.magicItemApi
      .assignToCharacter(
        character.characterId,
        {
          magicItemId,
        },
      )
      .pipe(
        finalize(() => {
          this.assigningItem.set(false);
        }),
      )
      .subscribe({
        next: (ownedItem) => {
          this.assignmentFeedback.set(
            `${ownedItem.magicItem.name} a été donné à ${character.characterName}.`,
          );

          this.selectedMagicItemId.set(null);
        },

        error: (error) => {
          this.characterActionError.set(
            typeof error.error?.message ===
              'string'
              ? error.error.message
              : 'Impossible d’attribuer cet objet.',
          );
        },
      });
  }

  protected openStatistics(
    character: ImportedCharacterResult,
  ): void {
    this.statisticsCharacter.set(character);
    this.statisticsInventory.set(null);
    this.statisticsLoading.set(true);
    this.characterActionError.set(null);

    this.magicItemApi
      .listCharacterItems(
        character.characterId,
      )
      .pipe(
        finalize(() => {
          this.statisticsLoading.set(false);
        }),
      )
      .subscribe({
        next: (inventory) => {
          this.statisticsInventory.set(
            inventory,
          );
        },

        error: (error) => {
          this.characterActionError.set(
            typeof error.error?.message ===
              'string'
              ? error.error.message
              : 'Impossible de charger les caractéristiques.',
          );

          this.statisticsCharacter.set(null);
        },
      });
  }

  protected closeStatistics(): void {
    this.statisticsCharacter.set(null);
    this.statisticsInventory.set(null);
  }

  protected abilityLabel(
    ability:
      EffectiveAbilityResponse['ability'],
  ): string {
    const labels: Record<
      EffectiveAbilityResponse['ability'],
      string
    > = {
      strength: 'Force',
      dexterity: 'Dextérité',
      constitution: 'Constitution',
      intelligence: 'Intelligence',
      wisdom: 'Sagesse',
      charisma: 'Charisme',
    };

    return labels[ability];
  }

  protected formatModifier(
    modifier: number,
  ): string {
    return modifier >= 0
      ? `+${modifier}`
      : `${modifier}`;
  }

  private loadCatalog(): void {
    this.catalogLoading.set(true);

    this.magicItemApi
      .listCatalog(this.campaignId())
      .pipe(
        finalize(() => {
          this.catalogLoading.set(false);
        }),
      )
      .subscribe({
        next: (catalog) => {
          this.catalog.set(catalog);
        },

        error: (error) => {
          this.characterActionError.set(
            typeof error.error?.message ===
              'string'
              ? error.error.message
              : 'Impossible de charger le catalogue des objets.',
          );
        },
      });
  }
}

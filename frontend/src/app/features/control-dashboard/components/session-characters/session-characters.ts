import {
  Component,
  OnInit,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { forkJoin } from 'rxjs';
import { finalize } from 'rxjs/operators';

import {
  DndReferenceApiService,
  ProgressionReference,
} from '@core/services/dnd-reference-api.service';

import {
  CharacterApiResponse,
  CharacterApiService,
} from '@core/services/character-api.service';

import {
  CharacterSessionStateApiResponse,
  CharacterSessionStateApiService,
} from '@core/services/character-session-state-api.service';

import {
  MagicItemApiService,
} from '@core/services/magic-item-api.service';

import {
  CharacterMagicItemInventoryResponse,
  CharacterMagicItemResponse,
  MagicItemResponse,
} from '@core/services/public-character-magic-item-api.service';

import {
  RestRequestApiResponse,
} from '@core/services/rest-request-api.service';

import {
  ActiveEffectTermination,
  MaximumHitPointAdjustment,
  SessionCharacterView,
} from './session-character.models';

import { RestRequests } from './rest-requests/rest-requests';
import { ActiveCharacterCard } from './active-character-card/active-character-card';
import { AvailableCharacters } from './available-characters/available-characters';
import { ItemAssignmentModal } from './item-assignment-modal/item-assignment-modal';
import { CharacterStatisticsModal } from './character-statistics-modal/character-statistics-modal';
import { CharacterProgressionModal } from './character-progression-modal/character-progression-modal';

export interface RestRequestResolution {
  requestId: number;
  approved: boolean;
}

@Component({
  selector: 'app-session-characters',
  imports: [
    RestRequests,
    ActiveCharacterCard,
    AvailableCharacters,
    ItemAssignmentModal,
    CharacterStatisticsModal,
    CharacterProgressionModal,
  ],
  templateUrl: './session-characters.html',
  styleUrl: './session-characters.scss',
})
export class SessionCharacters implements OnInit {
  private readonly progressionReferenceApi = inject(DndReferenceApiService);
  private readonly characterApi = inject(CharacterApiService);
  private readonly characterSessionStateApi = inject(
    CharacterSessionStateApiService,
  );
  private readonly magicItemApi = inject(MagicItemApiService);

  private readonly progressionReferences = signal<ProgressionReference[]>([]);
  private progressionRequestRevision = 0;
  private inventoryRequestRevision = 0;

  readonly campaignId = input.required<number>();
  readonly sessionId = input.required<number>();

  readonly restRequests = input.required<RestRequestApiResponse[]>();
  readonly resolvingRestRequestId = input<number | null>(null);
  readonly restRequestError = input<string | null>(null);
  readonly restRequestResolved = output<RestRequestResolution>();

  protected readonly campaignCharacters = signal<CharacterApiResponse[]>([]);
  protected readonly sessionStates =
    signal<CharacterSessionStateApiResponse[]>([]);

  protected readonly loading = signal(false);
  protected readonly refreshingCharacters = signal(false);
  protected readonly changingCharacterId = signal<number | null>(null);
  protected readonly changingLevelUpPermissionId = signal<number | null>(null);
  protected readonly changingMaximumHitPointsId = signal<number | null>(null);
  protected readonly endingActiveEffectId = signal<number | null>(null);
  protected readonly characterActionError = signal<string | null>(null);
  protected readonly characterActionFeedback = signal<string | null>(null);

  protected readonly catalog = signal<MagicItemResponse[]>([]);
  protected readonly catalogLoading = signal(false);
  protected readonly selectedCharacter =
    signal<SessionCharacterView | null>(null);
  protected readonly selectedMagicItemId = signal<number | null>(null);
  protected readonly assigningItem = signal(false);
  protected readonly assignmentFeedback = signal<string | null>(null);

  private readonly statisticsCharacterId = signal<number | null>(null);
  protected readonly statisticsError = signal<string | null>(null);
  protected readonly statisticsInventory =
    signal<CharacterMagicItemInventoryResponse | null>(null);
  protected readonly statisticsLoading = signal(false);
  protected readonly removingItem = signal(false);

  private readonly progressionCharacterId = signal<number | null>(null);
  private readonly selectedProgressionSlug = signal<string | null>(null);

  protected readonly progressionReferenceLoading = signal(false);
  protected readonly progressionReferenceError = signal<string | null>(null);
  protected readonly expandedStageIds =
    signal<ReadonlySet<number>>(new Set());
  protected readonly progressionTab =
    signal<'phases' | 'actions'>('phases');

  protected readonly characters = computed<SessionCharacterView[]>(() => {
    const states = this.sessionStates();

    return this.campaignCharacters().map(character => {
      const sessionState =
        states.find(state => state.character.id === character.id) ?? null;

      return {
        character,
        sessionState,
        participating: sessionState?.participating ?? false,
      };
    });
  });

  protected readonly activeCharacters = computed(() =>
    this.characters().filter(character => character.participating),
  );

  protected readonly availableCharacters = computed(() =>
    this.characters().filter(character => !character.participating),
  );

  protected readonly hasCharacters = computed(
    () => this.campaignCharacters().length > 0,
  );

  protected readonly hasActiveCharacters = computed(
    () => this.activeCharacters().length > 0,
  );

  protected readonly statisticsCharacter = computed(() =>
    this.characters().find(
      entry => entry.character.id === this.statisticsCharacterId(),
    ) ?? null,
  );

  protected readonly progressionCharacter = computed(() =>
    this.characters().find(
      entry => entry.character.id === this.progressionCharacterId(),
    ) ?? null,
  );

  protected readonly progressionDetails = computed(() => {
    const session = this.progressionCharacter()?.sessionState;

    return (session?.character.progressions ?? []).map(progression => {
      const reference = this.progressionReferences().find(
        definition => definition.id === progression.definitionId,
      );

      const current =
        session?.state.progressions.find(
          value => value.id === progression.slug,
        )?.currentValue ?? null;

      const stages = [...(reference?.stages ?? [])].sort(
        (a, b) => a.minimumValue - b.minimumValue,
      );

      const stage =
        current === null
          ? null
          : stages.find(candidate =>
              current >= candidate.minimumValue
              && (
                candidate.maximumValue === null
                || current <= candidate.maximumValue
              ),
            ) ?? null;

      return {
        ...progression,
        ...(reference ?? {}),
        current,
        stage,
        stages,
        referenceAvailable: reference !== undefined,
        adjustmentRules: [...(reference?.adjustmentRules ?? [])].sort(
          (a, b) =>
            a.displayOrder - b.displayOrder
            || a.id - b.id,
        ),
      };
    });
  });

  protected readonly selectedProgression = computed(() =>
    this.progressionDetails().find(
      entry => entry.slug === this.selectedProgressionSlug(),
    )
    ?? this.progressionDetails()[0]
    ?? null,
  );

  ngOnInit(): void {
    this.loadCharacters();
  }

  protected resolveRestRequest(
    requestId: number,
    approved: boolean,
  ): void {
    if (this.resolvingRestRequestId() !== null) {
      return;
    }

    this.restRequestResolved.emit({
      requestId,
      approved,
    });
  }

  protected addToSession(
    character: SessionCharacterView,
  ): void {
    if (this.changingCharacterId() !== null) {
      return;
    }

    this.changingCharacterId.set(character.character.id);
    this.clearCharacterFeedback();

    this.characterSessionStateApi
      .create(
        this.sessionId(),
        character.character.id,
      )
      .pipe(
        finalize(() =>
          this.changingCharacterId.set(null),
        ),
      )
      .subscribe({
        next: state => {
          this.upsertState(state);

          this.characterActionFeedback.set(
            `${character.character.name} participe maintenant à la session.`,
          );
        },

        error: error => {
          this.characterActionError.set(
            this.apiError(
              error,
              `Impossible d’ajouter ${character.character.name} à la session.`,
            ),
          );
        },
      });
  }

  protected removeFromSession(
    character: SessionCharacterView,
  ): void {
    if (this.changingCharacterId() !== null) {
      return;
    }

    this.changingCharacterId.set(character.character.id);
    this.clearCharacterFeedback();

    this.characterSessionStateApi
      .remove(
        this.sessionId(),
        character.character.id,
      )
      .pipe(
        finalize(() =>
          this.changingCharacterId.set(null),
        ),
      )
      .subscribe({
        next: state => {
          this.upsertState(state);

          this.characterActionFeedback.set(
            `${character.character.name} a été retiré de cette session.`,
          );
        },

        error: error => {
          this.characterActionError.set(
            this.apiError(
              error,
              `Impossible de retirer ${character.character.name} de la session.`,
            ),
          );
        },
      });
  }

  protected toggleLevelUpPermission(
    character: SessionCharacterView,
  ): void {
    const state = character.sessionState;

    if (
      !state
      || !character.participating
      || this.changingLevelUpPermissionId() !== null
    ) {
      return;
    }

    const allowed = !state.levelUpAllowed;

    this.changingLevelUpPermissionId.set(
      character.character.id,
    );
    this.clearCharacterFeedback();

    this.characterSessionStateApi
      .setLevelUpPermission(
        this.sessionId(),
        character.character.id,
        allowed,
      )
      .pipe(
        finalize(() =>
          this.changingLevelUpPermissionId.set(null),
        ),
      )
      .subscribe({
        next: updatedState => {
          this.upsertState(updatedState);

          this.characterActionFeedback.set(
            allowed
              ? `La montée de niveau de ${character.character.name} est autorisée.`
              : `L’autorisation de montée de niveau de ${character.character.name} a été retirée.`,
          );
        },

        error: error => {
          this.characterActionError.set(
            this.apiError(
              error,
              `Impossible de modifier l’autorisation de montée de niveau de ${character.character.name}.`,
            ),
          );
        },
      });
  }

  protected adjustMaximumHitPoints(
    adjustment: MaximumHitPointAdjustment,
  ): void {
    const {
      character,
      amount,
      direction,
    } = adjustment;

    const characterId = character.character.id;

    if (
      amount <= 0
      || this.changingMaximumHitPointsId() !== null
      || !character.participating
    ) {
      return;
    }

    this.changingMaximumHitPointsId.set(characterId);
    this.clearCharacterFeedback();

    this.characterSessionStateApi
      .adjustMaximumHitPoints(
        this.sessionId(),
        characterId,
        amount * direction,
      )
      .pipe(
        finalize(() =>
          this.changingMaximumHitPointsId.set(null),
        ),
      )
      .subscribe({
        next: state => {
          this.upsertState(state);

          this.characterActionFeedback.set(
            direction < 0
              ? `Le maximum de PV de ${character.character.name} a été réduit de ${amount}.`
              : `Le maximum de PV de ${character.character.name} a été augmenté de ${amount}.`,
          );
        },

        error: error => {
          this.characterActionError.set(
            this.apiError(
              error,
              `Impossible de modifier le maximum de PV de ${character.character.name}.`,
            ),
          );
        },
      });
  }

  protected refreshCharacters(): void {
    if (this.refreshingCharacters()) {
      return;
    }

    this.refreshingCharacters.set(true);
    this.clearCharacterFeedback();

    this.characterSessionStateApi
      .list(this.sessionId())
      .pipe(
        finalize(() =>
          this.refreshingCharacters.set(false),
        ),
      )
      .subscribe({
        next: states =>
          this.sessionStates.set(states),

        error: error => {
          this.characterActionError.set(
            this.apiError(
              error,
              'Impossible d’actualiser les personnages.',
            ),
          );
        },
      });
  }

  protected openItemAssignment(
    character: SessionCharacterView,
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

  protected assignSelectedItem(): void {
    const character = this.selectedCharacter();
    const magicItemId = this.selectedMagicItemId();

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
        character.character.id,
        { magicItemId },
      )
      .pipe(
        finalize(() =>
          this.assigningItem.set(false),
        ),
      )
      .subscribe({
        next: ownedItem => {
          this.assignmentFeedback.set(
            `${ownedItem.magicItem.name} a été donné à ${character.character.name}.`,
          );

          this.selectedMagicItemId.set(null);
        },

        error: error => {
          this.characterActionError.set(
            this.apiError(
              error,
              'Impossible d’attribuer cet objet.',
            ),
          );
        },
      });
  }

  protected openStatistics(
    character: SessionCharacterView,
  ): void {
    this.closeProgression();

    this.statisticsCharacterId.set(
      character.character.id,
    );

    const revision =
      ++this.inventoryRequestRevision;

    this.statisticsError.set(null);
    this.refreshCharacters();
    this.statisticsInventory.set(null);
    this.statisticsLoading.set(true);
    this.characterActionError.set(null);

    this.magicItemApi
      .listCharacterItems(character.character.id)
      .pipe(
        finalize(() => {
          if (
            revision
            === this.inventoryRequestRevision
          ) {
            this.statisticsLoading.set(false);
          }
        }),
      )
      .subscribe({
        next: inventory => {
          if (
            revision
            === this.inventoryRequestRevision
          ) {
            this.statisticsInventory.set(inventory);
          }
        },

        error: error => {
          if (
            revision
            !== this.inventoryRequestRevision
          ) {
            return;
          }

          this.statisticsError.set(
            this.apiError(
              error,
              'Impossible de charger les caractéristiques.',
            ),
          );
        },
      });
  }

  protected removeOwnedItem(item: CharacterMagicItemResponse): void {
    const character = this.statisticsCharacter();
    if (!character || this.removingItem() || !window.confirm(`Retirer un exemplaire de « ${item.magicItem.name} » à ${character.character.name} ?`)) {
      return;
    }
    const revision = this.inventoryRequestRevision;
    this.removingItem.set(true);
    this.statisticsError.set(null);
    this.magicItemApi.removeFromCharacter(character.character.id, item.id)
      .pipe(finalize(() => this.removingItem.set(false)))
      .subscribe({
        next: () => {
          if (revision === this.inventoryRequestRevision) this.openStatistics(character);
          else this.refreshCharacters();
        },
        error: error => {
          if (revision === this.inventoryRequestRevision) {
            this.statisticsError.set(this.apiError(error, 'Impossible de retirer cet objet.'));
          }
        },
      });
  }

  protected closeStatistics(): void {
    ++this.inventoryRequestRevision;

    this.statisticsCharacterId.set(null);
    this.statisticsInventory.set(null);
    this.statisticsLoading.set(false);
    this.statisticsError.set(null);
  }

  protected openProgression(
    character: SessionCharacterView,
  ): void {
    this.closeStatistics();

    this.selectedProgressionSlug.set(null);
    this.progressionTab.set('phases');
    this.expandedStageIds.set(new Set());
    this.progressionCharacterId.set(
      character.character.id,
    );

    this.refreshCharacters();
    this.loadProgressionReferences();
  }

  protected loadProgressionReferences(): void {
    const revision =
      ++this.progressionRequestRevision;

    this.progressionReferences.set([]);
    this.progressionReferenceError.set(null);
    this.progressionReferenceLoading.set(true);

    this.progressionReferenceApi
      .getProgressions()
      .pipe(
        finalize(() => {
          if (
            revision
            === this.progressionRequestRevision
          ) {
            this.progressionReferenceLoading.set(false);
          }
        }),
      )
      .subscribe({
        next: response => {
          if (
            revision
            === this.progressionRequestRevision
          ) {
            this.progressionReferences.set(
              response.progressions,
            );
          }
        },

        error: error => {
          if (
            revision
            === this.progressionRequestRevision
          ) {
            this.progressionReferenceError.set(
              this.apiError(
                error,
                'Impossible de charger le référentiel des progressions.',
              ),
            );
          }
        },
      });
  }

  protected closeProgression(): void {
    ++this.progressionRequestRevision;

    this.progressionReferences.set([]);
    this.progressionReferenceLoading.set(false);
    this.progressionReferenceError.set(null);
    this.expandedStageIds.set(new Set());
    this.progressionCharacterId.set(null);
  }

  protected selectProgression(
    slug: string,
  ): void {
    this.selectedProgressionSlug.set(slug);
    this.progressionTab.set('phases');
    this.expandedStageIds.set(new Set());
  }

  protected toggleStage(id: number): void {
    this.expandedStageIds.update(ids => {
      const next = new Set(ids);

      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }

      return next;
    });
  }

  private loadCharacters(): void {
    this.loading.set(true);
    this.clearCharacterFeedback();

    forkJoin({
      characters:
        this.characterApi.list(this.campaignId()),
      states:
        this.characterSessionStateApi.list(
          this.sessionId(),
        ),
    })
      .pipe(
        finalize(() =>
          this.loading.set(false),
        ),
      )
      .subscribe({
        next: result => {
          this.campaignCharacters.set(
            result.characters,
          );

          this.sessionStates.set(
            result.states,
          );
        },

        error: error => {
          this.characterActionError.set(
            this.apiError(
              error,
              'Impossible de charger les personnages.',
            ),
          );
        },
      });
  }

  private upsertState(
    state: CharacterSessionStateApiResponse,
  ): void {
    this.sessionStates.update(states => {
      const index = states.findIndex(
        candidate => candidate.id === state.id,
      );

      if (index === -1) {
        return [...states, state];
      }

      return states.map(candidate =>
        candidate.id === state.id
          ? state
          : candidate,
      );
    });
  }

  private loadCatalog(): void {
    this.catalogLoading.set(true);

    this.magicItemApi
      .listCatalog(this.campaignId())
      .pipe(
        finalize(() =>
          this.catalogLoading.set(false),
        ),
      )
      .subscribe({
        next: catalog =>
          this.catalog.set(catalog),

        error: error => {
          this.characterActionError.set(
            this.apiError(
              error,
              'Impossible de charger le catalogue des objets.',
            ),
          );
        },
      });
  }

  private clearCharacterFeedback(): void {
    this.characterActionError.set(null);
    this.characterActionFeedback.set(null);
  }

  private apiError(
    error: unknown,
    fallback: string,
  ): string {
    if (
      typeof error === 'object'
      && error !== null
      && 'error' in error
      && typeof error.error === 'object'
      && error.error !== null
      && 'message' in error.error
      && typeof error.error.message === 'string'
    ) {
      return error.error.message;
    }

    return fallback;
  }

  protected terminateActiveEffect(
  termination: ActiveEffectTermination,
): void {
  if (this.endingActiveEffectId() !== null) {
    return;
  }

  const {
    character,
    effectId,
  } = termination;

  this.endingActiveEffectId.set(effectId);
  this.clearCharacterFeedback();

  this.characterSessionStateApi
    .endActiveEffect(
      this.sessionId(),
      character.character.id,
      effectId,
    )
    .pipe(
      finalize(() =>
        this.endingActiveEffectId.set(null),
      ),
    )
    .subscribe({
      next: state => {
        this.upsertState(state);

        this.characterActionFeedback.set(
          `L’effet actif de ${character.character.name} a pris fin.`,
        );
      },

      error: error => {
        this.characterActionError.set(
          this.apiError(
            error,
            `Impossible de terminer l’effet de ${character.character.name}.`,
          ),
        );
      },
    });
}
}

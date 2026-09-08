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

interface SessionCharacterView {
  character: CharacterApiResponse;
  sessionState: CharacterSessionStateApiResponse | null;
  participating: boolean;
}

@Component({
  selector: 'app-session-characters',
  imports: [],
  templateUrl: './session-characters.html',
  styleUrl: './session-characters.scss',
})
export class SessionCharacters implements OnInit {
  private readonly characterApi = inject(CharacterApiService);
  private readonly characterSessionStateApi = inject(
    CharacterSessionStateApiService,
  );
  private readonly magicItemApi = inject(MagicItemApiService);

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
  protected readonly refreshingHitPoints = signal(false);
  protected readonly changingCharacterId = signal<number | null>(null);
  protected readonly characterActionError = signal<string | null>(null);
  protected readonly characterActionFeedback = signal<string | null>(null);

  protected readonly catalog = signal<MagicItemResponse[]>([]);
  protected readonly catalogLoading = signal(false);
  protected readonly selectedCharacter = signal<SessionCharacterView | null>(
    null,
  );
  protected readonly selectedMagicItemId = signal<number | null>(null);
  protected readonly assigningItem = signal(false);
  protected readonly assignmentFeedback = signal<string | null>(null);

  protected readonly statisticsCharacter =
    signal<SessionCharacterView | null>(null);
  protected readonly statisticsInventory =
    signal<CharacterMagicItemInventoryResponse | null>(null);
  protected readonly statisticsLoading = signal(false);

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

  protected readonly selectedMagicItem = computed(() => {
    const selectedId = this.selectedMagicItemId();

    return selectedId === null
      ? null
      : this.catalog().find(item => item.id === selectedId) ?? null;
  });

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

    this.restRequestResolved.emit({ requestId, approved });
  }

  protected addToSession(character: SessionCharacterView): void {
    if (this.changingCharacterId() !== null) {
      return;
    }

    this.changingCharacterId.set(character.character.id);
    this.clearCharacterFeedback();

    this.characterSessionStateApi
      .create(this.sessionId(), character.character.id)
      .pipe(finalize(() => this.changingCharacterId.set(null)))
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

  protected removeFromSession(character: SessionCharacterView): void {
    if (this.changingCharacterId() !== null) {
      return;
    }

    this.changingCharacterId.set(character.character.id);
    this.clearCharacterFeedback();

    this.characterSessionStateApi
      .remove(this.sessionId(), character.character.id)
      .pipe(finalize(() => this.changingCharacterId.set(null)))
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

  protected refreshHitPoints(): void {
    if (this.refreshingHitPoints()) {
      return;
    }

    this.refreshingHitPoints.set(true);
    this.clearCharacterFeedback();

    this.characterSessionStateApi
      .list(this.sessionId())
      .pipe(finalize(() => this.refreshingHitPoints.set(false)))
      .subscribe({
        next: states => this.sessionStates.set(states),
        error: error => {
          this.characterActionError.set(
            this.apiError(error, 'Impossible d’actualiser les personnages.'),
          );
        },
      });
  }

  protected playerPortalUrl(
    character: SessionCharacterView,
  ): string | null {
    const accessToken = character.sessionState?.accessToken;

    if (!accessToken || !character.participating) {
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

  protected currentHitPoints(
    character: SessionCharacterView,
  ): number | null {
    return character.sessionState?.state.hitPoints.current ?? null;
  }

  protected temporaryHitPoints(character: SessionCharacterView): number {
    return character.sessionState?.state.hitPoints.temporary ?? 0;
  }

  protected maximumHitPoints(
    character: SessionCharacterView,
  ): number | null {
    return character.sessionState?.character.hitPoints.maximumValue ?? null;
  }

  protected hitPointPercentage(character: SessionCharacterView): number {
    const current = this.currentHitPoints(character);
    const maximum = this.maximumHitPoints(character);

    if (current === null || maximum === null || maximum <= 0) {
      return 0;
    }

    return Math.min(100, Math.max(0, (current / maximum) * 100));
  }

  protected hitPointTone(
    character: SessionCharacterView,
  ): 'healthy' | 'wounded' | 'critical' {
    const percentage = this.hitPointPercentage(character);

    if (percentage <= 25) {
      return 'critical';
    }

    if (percentage <= 50) {
      return 'wounded';
    }

    return 'healthy';
  }

  protected characterInitial(name: string): string {
    return name.trim().charAt(0).toUpperCase();
  }

  protected characterSummary(character: CharacterApiResponse): string {
    const classes = character.classLevels.reduce<Record<string, number>>(
      (summary, level) => {
        summary[level.className] = (summary[level.className] ?? 0) + 1;
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

  protected restTypeLabel(type: 'short-rest' | 'long-rest'): string {
    return type === 'short-rest' ? 'Repos court' : 'Repos long';
  }

  protected openItemAssignment(character: SessionCharacterView): void {
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

  protected selectMagicItem(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    this.selectedMagicItemId.set(value ? Number(value) : null);
  }

  protected assignSelectedItem(): void {
    const character = this.selectedCharacter();
    const magicItemId = this.selectedMagicItemId();

    if (!character || magicItemId === null || this.assigningItem()) {
      return;
    }

    this.assigningItem.set(true);
    this.assignmentFeedback.set(null);
    this.characterActionError.set(null);

    this.magicItemApi
      .assignToCharacter(character.character.id, { magicItemId })
      .pipe(finalize(() => this.assigningItem.set(false)))
      .subscribe({
        next: ownedItem => {
          this.assignmentFeedback.set(
            `${ownedItem.magicItem.name} a été donné à ${character.character.name}.`,
          );
          this.selectedMagicItemId.set(null);
        },
        error: error => {
          this.characterActionError.set(
            this.apiError(error, 'Impossible d’attribuer cet objet.'),
          );
        },
      });
  }

  protected openStatistics(character: SessionCharacterView): void {
    this.statisticsCharacter.set(character);
    this.statisticsInventory.set(null);
    this.statisticsLoading.set(true);
    this.characterActionError.set(null);

    this.magicItemApi
      .listCharacterItems(character.character.id)
      .pipe(finalize(() => this.statisticsLoading.set(false)))
      .subscribe({
        next: inventory => this.statisticsInventory.set(inventory),
        error: error => {
          this.characterActionError.set(
            this.apiError(
              error,
              'Impossible de charger les caractéristiques.',
            ),
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
    ability: EffectiveAbilityResponse['ability'],
  ): string {
    const labels: Record<EffectiveAbilityResponse['ability'], string> = {
      strength: 'Force',
      dexterity: 'Dextérité',
      constitution: 'Constitution',
      intelligence: 'Intelligence',
      wisdom: 'Sagesse',
      charisma: 'Charisme',
    };

    return labels[ability];
  }

  protected formatModifier(modifier: number): string {
    return modifier >= 0 ? `+${modifier}` : `${modifier}`;
  }

  private loadCharacters(): void {
    this.loading.set(true);
    this.clearCharacterFeedback();

    forkJoin({
      characters: this.characterApi.list(this.campaignId()),
      states: this.characterSessionStateApi.list(this.sessionId()),
    })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: result => {
          this.campaignCharacters.set(result.characters);
          this.sessionStates.set(result.states);
        },
        error: error => {
          this.characterActionError.set(
            this.apiError(error, 'Impossible de charger les personnages.'),
          );
        },
      });
  }

  private upsertState(state: CharacterSessionStateApiResponse): void {
    this.sessionStates.update(states => {
      const index = states.findIndex(candidate => candidate.id === state.id);

      if (index === -1) {
        return [...states, state];
      }

      return states.map(candidate =>
        candidate.id === state.id ? state : candidate,
      );
    });
  }

  private loadCatalog(): void {
    this.catalogLoading.set(true);

    this.magicItemApi
      .listCatalog(this.campaignId())
      .pipe(finalize(() => this.catalogLoading.set(false)))
      .subscribe({
        next: catalog => this.catalog.set(catalog),
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

  private apiError(error: unknown, fallback: string): string {
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
}

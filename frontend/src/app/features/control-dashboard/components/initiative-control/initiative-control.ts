import {
  Component,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { forkJoin } from 'rxjs';
import { finalize } from 'rxjs/operators';

import {
  InitiativeDraftParticipant,
  InitiativeParticipant,
  InitiativeState,
} from '@core/models/live-session-state.model';

import { CampaignFigureApiService } from '@core/services/campaign-figure-api.service';
import { CharacterSessionStateApiService } from '@core/services/character-session-state-api.service';

@Component({
  selector: 'app-initiative-control',
  imports: [FormsModule],
  templateUrl: './initiative-control.html',
  styleUrl: './initiative-control.scss',
})
export class InitiativeControl {
  private readonly characterSessionStateApi = inject(CharacterSessionStateApiService);
  private readonly figureApi = inject(CampaignFigureApiService);

  readonly campaignId = input.required<number>();
  readonly sessionId = input.required<number>();
  readonly initiative = input<InitiativeState | undefined>();
  readonly draftParticipants = input<InitiativeDraftParticipant[]>([]);

  readonly initiativeRequested = output<void>();
  readonly initiativeStarted = output<InitiativeParticipant[]>();
  readonly initiativePrevious = output<void>();
  readonly initiativeNext = output<void>();
  readonly initiativeEnded = output<void>();
  readonly draftChanged = output<InitiativeDraftParticipant[]>();

  protected readonly participants = signal<InitiativeDraftParticipant[]>([]);
  protected readonly importingCharacters = signal(false);
  protected readonly importError = signal<string | null>(null);

  protected readonly canStart = computed(() => {
    const participants = this.participants();

    return (
      participants.length > 0
      && participants.every(
        participant =>
          participant.name.trim() !== ''
          && participant.initiative !== null,
      )
    );
  });

  protected readonly currentParticipant = computed(() => {
    const initiative = this.initiative();

    if (
      initiative?.status !== 'active'
      || initiative.participants.length === 0
    ) {
      return null;
    }

    return (
      initiative.participants[
        initiative.currentIndex
      ] ?? null
    );
  });

  protected readonly nextParticipant = computed(() => {
    const initiative = this.initiative();

    if (
      initiative?.status !== 'active'
      || initiative.participants.length === 0
    ) {
      return null;
    }

    const nextIndex =
      (initiative.currentIndex + 1)
      % initiative.participants.length;

    return initiative.participants[nextIndex] ?? null;
  });

  constructor() {
    effect(() => {
      this.participants.set(
        this.draftParticipants(),
      );
    });
  }

  protected requestInitiative(): void {
    this.initiativeRequested.emit();
  }

  protected importSessionCharacters(): void {
    if (this.importingCharacters()) {
      return;
    }

    this.importingCharacters.set(true);
    this.importError.set(null);

    forkJoin({
      sessionStates:
        this.characterSessionStateApi.list(
          this.sessionId(),
        ),

      figures:
        this.figureApi.list(
          this.campaignId(),
        ),
    })
      .pipe(
        finalize(() => {
          this.importingCharacters.set(false);
        }),
      )
      .subscribe({
        next: ({ sessionStates, figures }) => {
          const participatingStates =
            sessionStates.filter(
              state => state.participating,
            );

          const next = [
            ...this.participants(),
          ];

          for (const state of participatingStates) {
            const characterId =
              state.character.id;

            const currentHitPoints =
              state.state.hitPoints.current;

            const maximumHitPoints =
              state.character.hitPoints.maximumValue;

            const bloodied =
              maximumHitPoints !== null
              && maximumHitPoints > 0
              && currentHitPoints
                <= maximumHitPoints / 2;

            const figure =
              figures.find(
                figure =>
                  figure.characterId
                  === characterId,
              );

            const imageUrl =
              figure?.portrait?.url;

            const existingIndex =
              next.findIndex(
                participant =>
                  participant.characterId
                  === characterId,
              );

            if (existingIndex >= 0) {
              next[existingIndex] = {
                ...next[existingIndex],
                name: state.character.name,
                currentHitPoints,
                maximumHitPoints,
                bloodied,
                imageUrl,
              };

              continue;
            }

            next.push({
              id: `character-${characterId}`,
              characterId,
              name: state.character.name,
              initiative: null,
              currentHitPoints,
              maximumHitPoints,
              bloodied,
              imageUrl,
            });
          }

          this.updateParticipants(next);
        },

        error: error => {
          console.error(
            'Impossible de charger les participants à l’initiative.',
            error,
          );

          this.importError.set(
            'Impossible de charger les personnages de la session.',
          );
        },
      });
  }

  protected addParticipant(): void {
    this.updateParticipants([
      ...this.participants(),
      {
        id: crypto.randomUUID(),
        name: '',
        initiative: null,
        bloodied: false,
      },
    ]);
  }

  protected removeParticipant(id: string): void {
    this.updateParticipants(
      this.participants().filter(
        participant =>
          participant.id !== id,
      ),
    );
  }

  protected updateName(
    id: string,
    name: string,
  ): void {
    this.updateParticipants(
      this.participants().map(
        participant =>
          participant.id === id
            ? {
                ...participant,
                name,
              }
            : participant,
      ),
    );
  }

  protected updateInitiative(
    id: string,
    value: number | null,
  ): void {
    this.updateParticipants(
      this.participants().map(
        participant =>
          participant.id === id
            ? {
                ...participant,
                initiative: value,
              }
            : participant,
      ),
    );
  }

  protected toggleBloodied(id: string): void {
    this.updateParticipants(
      this.participants().map(
        participant => {
          if (
            participant.id !== id
            || participant.characterId !== undefined
          ) {
            return participant;
          }

          return {
            ...participant,
            bloodied: !participant.bloodied,
          };
        },
      ),
    );
  }

  protected startCombat(): void {
    if (!this.canStart()) {
      return;
    }

    const participants: InitiativeParticipant[] =
      this.participants()
        .map(participant => ({
          id: participant.id,
          name: participant.name.trim(),
          initiative:
            participant.initiative ?? 0,
          characterId:
            participant.characterId,
          imageUrl:
            participant.imageUrl,
          bloodied:
            participant.bloodied,
        }))
        .sort(
          (a, b) =>
            b.initiative - a.initiative,
        );

    this.initiativeStarted.emit(
      participants,
    );
  }

  protected previousTurn(): void {
    this.initiativePrevious.emit();
  }

  protected nextTurn(): void {
    this.initiativeNext.emit();
  }

  protected endInitiative(): void {
    this.initiativeEnded.emit();
  }

  private updateParticipants(
    participants: InitiativeDraftParticipant[],
  ): void {
    this.participants.set(
      participants,
    );

    this.draftChanged.emit(
      participants,
    );
  }
}

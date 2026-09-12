import {
  Component,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { finalize } from 'rxjs/operators';
import { forkJoin } from 'rxjs';

import {
  CampaignFigureApiService,
} from '@core/services/campaign-figure-api.service';
import {
  InitiativeParticipant,
  InitiativeState,
} from '@core/models/live-session-state.model';

import {
  CharacterSessionStateApiService,
} from '@core/services/character-session-state-api.service';

interface InitiativeDraftParticipant {
  id: string;
  name: string;
  initiative: number | null;

  characterId?: number;
  imageUrl?: string;
  currentHitPoints?: number;
  maximumHitPoints?: number | null;

  bloodied: boolean;
}

@Component({
  selector: 'app-initiative-control',
  imports: [FormsModule],
  templateUrl: './initiative-control.html',
  styleUrl: './initiative-control.scss',
})
export class InitiativeControl {
  private readonly characterSessionStateApi = inject(
    CharacterSessionStateApiService,
  );
  private readonly figureApi = inject(CampaignFigureApiService);
  readonly initiativePrevious = output<void>();
  readonly initiativeNext = output<void>();
  readonly campaignId = input.required<number>();
  readonly sessionId = input.required<number>();
  readonly initiative = input<InitiativeState | undefined>();
readonly initiativeEnded = output<void>();

protected endInitiative(): void {
  this.initiativeEnded.emit();
}
  readonly initiativeRequested = output<void>();
  readonly initiativeStarted = output<InitiativeParticipant[]>();

  protected readonly participants =
    signal<InitiativeDraftParticipant[]>([]);

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
}).subscribe({
  next: ({ sessionStates, figures }) => {
    const participatingStates =
      sessionStates.filter(
        state => state.participating,
      );

    this.participants.update(
      participants => {
        const next = [...participants];

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

          const figure = figures.find(
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

        return next;
      },
    );
  },

  error: error => {
    console.error(
      'Impossible de charger les participants à l’initiative.',
      error,
    );
  },
});
  }

  protected addParticipant(): void {
    this.participants.update(participants => [
      ...participants,
      {
        id: crypto.randomUUID(),
        name: '',
        initiative: null,
        bloodied: false,
      },
    ]);
  }

  protected removeParticipant(id: string): void {
    this.participants.update(participants =>
      participants.filter(
        participant => participant.id !== id,
      ),
    );
  }

  protected updateName(
    id: string,
    name: string,
  ): void {
    this.participants.update(participants =>
      participants.map(participant =>
        participant.id === id
          ? { ...participant, name }
          : participant,
      ),
    );
  }

  protected updateInitiative(
    id: string,
    value: number | null,
  ): void {
    this.participants.update(participants =>
      participants.map(participant =>
        participant.id === id
          ? { ...participant, initiative: value }
          : participant,
      ),
    );
  }

  protected toggleBloodied(id: string): void {
    this.participants.update(participants =>
      participants.map(participant => {
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
      }),
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
          initiative: participant.initiative ?? 0,
          characterId: participant.characterId,
          bloodied: participant.bloodied,
          imageUrl: participant.imageUrl,
        }))
        .sort(
          (a, b) => b.initiative - a.initiative,
        );

        console.log(
  'initiative participants',
  participants,
);

    this.initiativeStarted.emit(participants);
  }

  protected previousTurn(): void {
    this.initiativePrevious.emit();
  }

  protected nextTurn(): void {
    this.initiativeNext.emit();
  }

protected readonly currentParticipant =
  computed(() => {
    const initiative = this.initiative();

    if (
      initiative?.status !== 'active'
      || initiative.participants.length === 0
    ) {
      return null;
    }

    const current =
      initiative.participants[
        initiative.currentIndex
      ] ?? null;

    console.log(
      'display current participant',
      current,
    );

    return current;
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
}

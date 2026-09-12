import {
  Component,
  computed,
  input,
} from '@angular/core';

import {
  InitiativeState,
} from '@core/models/live-session-state.model';

@Component({
  selector: 'app-initiative-turn-display',
  imports: [],
  templateUrl: './initiative-turn-display.html',
  styleUrl: './initiative-turn-display.scss',
})
export class InitiativeTurnDisplay {
  readonly initiative = input<InitiativeState | undefined>();

  protected readonly currentParticipant = computed(() => {
    const initiative = this.initiative();

    if (
      initiative?.status !== 'active'
      || initiative.participants.length === 0
    ) {
      return null;
    }

    return initiative.participants[initiative.currentIndex] ?? null;
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

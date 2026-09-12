
import {
  Component,
  DestroyRef,
  effect,
  inject,
  input,
  signal,
} from '@angular/core';

import {
  InitiativeState,
} from '@core/models/live-session-state.model';

@Component({
  selector: 'app-initiative-cinematic',
  imports: [],
  templateUrl: './initiative-cinematic.html',
  styleUrl: './initiative-cinematic.scss',
})
export class InitiativeCinematic {
  private static readonly DURATION = 10_000;

  private readonly destroyRef =
    inject(DestroyRef);

  readonly initiative =
    input<InitiativeState | undefined>();

  protected readonly visible =
    signal(false);

  private lastRequestedAt?: number;

  private hideTimer?: ReturnType<
    typeof setTimeout
  >;

  constructor() {
    effect(() => {
      const initiative =
        this.initiative();

      if (
        initiative?.status !== 'requested'
        || !initiative.requestedAt
        || initiative.requestedAt ===
          this.lastRequestedAt
      ) {
        return;
      }

      this.lastRequestedAt =
        initiative.requestedAt;

      if (this.hideTimer) {
        clearTimeout(this.hideTimer);
      }

      const elapsed =
        Date.now() -
        initiative.requestedAt;

      const remaining =
        InitiativeCinematic.DURATION -
        elapsed;

      if (remaining <= 0) {
        this.visible.set(false);
        return;
      }

      this.visible.set(true);

      this.hideTimer =
        setTimeout(() => {
          this.visible.set(false);
        }, remaining);
    });

    this.destroyRef.onDestroy(() => {
      if (this.hideTimer) {
        clearTimeout(this.hideTimer);
      }
    });
  }
}

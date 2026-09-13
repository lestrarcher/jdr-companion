import {
  Component,
  ElementRef,
  effect,
  input,
  signal,
  viewChild,
} from '@angular/core';

import {
  InitiativeState,
} from '@core/models/live-session-state.model';

@Component({
  selector: 'app-initiative-cinematic',
  templateUrl: './initiative-cinematic.html',
  styleUrl: './initiative-cinematic.scss',
})
export class InitiativeCinematic {
  readonly initiative = input<InitiativeState | undefined>();

  protected readonly visible = signal(false);

  private readonly initiativeVideo = viewChild.required<ElementRef<HTMLVideoElement>>('initiativeVideo');

  private lastRequestedAt: number | null = null;
  private hideTimeout: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    effect(() => {
      const initiative = this.initiative();

      if (
        initiative?.status !== 'requested'
        || initiative.requestedAt === this.lastRequestedAt
      ) {
        return;
      }

      this.lastRequestedAt = initiative.requestedAt;

      this.playCinematic();
    });
  }

  private playCinematic(): void {
    if (this.hideTimeout !== null) {
      clearTimeout(this.hideTimeout);
    }

    const video = this.initiativeVideo().nativeElement;

    video.pause();
    video.currentTime = 0;

    void video.play().catch(error => {
      console.warn(
        'Impossible de lancer la vidéo d’initiative.',
        error,
      );
    });

    this.visible.set(true);

    this.hideTimeout = setTimeout(() => {
      this.visible.set(false);
      video.pause();

      this.hideTimeout = null;
    }, 10_000);
  }
}

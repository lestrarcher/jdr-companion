import {
  Component,
  computed,
  input,
  output,
} from '@angular/core';

import { GameSessionStatus } from '@core/services/game-session-api.service';

@Component({
  selector: 'app-session-controls',
  imports: [],
  templateUrl: './session-controls.html',
  styleUrl: './session-controls.scss',
})
export class SessionControls {
  readonly status =
    input.required<GameSessionStatus>();

  readonly updating = input(false);
  readonly fogEnabled = input(false);
  readonly cinematicEnabled = input(false);

  readonly displayUrl =
    input.required<string>();

  readonly error =
    input<string | null>(null);

  readonly sessionOpened =
    output<void>();

  readonly sessionClosed =
    output<void>();

  readonly fogToggled =
    output<void>();

  readonly cinematicToggled =
    output<void>();

  readonly sessionDrafted =
    output<void>();

  protected readonly statusLabel = computed(() => {
    switch (this.status()) {
      case 'live':
        return 'Session ouverte';

      case 'closed':
        return 'Session terminée';

      case 'draft':
      default:
        return 'Session en préparation';
    }
  });

  protected openSession(): void {
    this.sessionOpened.emit();
  }

  protected closeSession(): void {
    this.sessionClosed.emit();
  }

  protected toggleFog(): void {
    this.fogToggled.emit();
  }

  protected toggleCinematic(): void {
    this.cinematicToggled.emit();
  }

  protected draftSession(): void {
    this.sessionDrafted.emit();
  }
}

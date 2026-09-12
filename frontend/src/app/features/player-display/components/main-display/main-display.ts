import {
  Component,
  input,
} from '@angular/core';

import {
  DisplayedMediaState,
  InitiativeState,
} from '@core/models/live-session-state.model';

import {
  InitiativeTurnDisplay,
} from '../initiative-turn-display/initiative-turn-display';

@Component({
  selector: 'app-main-display',
  imports: [
    InitiativeTurnDisplay,
  ],
  templateUrl: './main-display.html',
  styleUrl: './main-display.scss',
  host: {
    '[class.main-display-host--cinematic]':
      'cinematic()',
  },
})
export class MainDisplay {
  readonly cinematic = input(false);

  readonly displayedMedia =
    input<DisplayedMediaState | null>(null);

  readonly initiative =
    input<InitiativeState | undefined>();
}

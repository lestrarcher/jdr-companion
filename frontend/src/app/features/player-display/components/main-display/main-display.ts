import { Component, input } from '@angular/core';

import { DisplayedMediaState } from '@core/models/live-session-state.model';

@Component({
  selector: 'app-main-display',
  imports: [],
  templateUrl: './main-display.html',
  styleUrl: './main-display.scss',
  host: {
    '[class.main-display-host--cinematic]': 'cinematic()',
  },
})
export class MainDisplay {
  readonly cinematic = input(false);
  readonly displayedMedia = input<DisplayedMediaState | null>(null);
}

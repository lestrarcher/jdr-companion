import {
  Component,
  computed,
  input,
} from '@angular/core';

import { LiveSessionState } from '@core/models/live-session-state.model';

@Component({
  imports: [],
  selector: 'app-world-header',
  styleUrl: './world-header.scss',
  templateUrl: './world-header.html',
})
export class WorldHeader {
  readonly campaignLabel =
    input.required<string>();

  readonly state =
    input<LiveSessionState | null>(null);

protected readonly weather =
  computed(() => {
    const state = this.state();

    if (
      !state?.showWeather ||
      !state.weather
    ) {
      return null;
    }

    return state.weather;
  });

protected readonly moon =
  computed(() => {
    const state = this.state();

    if (
      !state?.showMoonPhase ||
      !state.moon
    ) {
      return null;
    }

    return state.moon;
  });

protected readonly showSeparator =
  computed(
    () =>
      this.weather() !== null &&
      this.moon() !== null,
  );
}

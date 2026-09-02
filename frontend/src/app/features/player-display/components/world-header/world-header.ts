import { Component, input } from '@angular/core';

import { LiveSessionState } from '@core/models/live-session-state.model';

@Component({
  imports: [],
  selector: 'app-world-header',
  styleUrl: './world-header.scss',
  templateUrl: './world-header.html',
})
export class WorldHeader {
  readonly campaignLabel = input.required<string>();
  readonly state = input<LiveSessionState | null>(null);
}

import {
  Component,
  input,
  output,
} from '@angular/core';

import {
  InitiativeState,
} from '@core/models/live-session-state.model';

@Component({
  selector: 'app-initiative-control',
  imports: [],
  templateUrl: './initiative-control.html',
  styleUrl: './initiative-control.scss',
})
export class InitiativeControl {
  readonly initiative =
    input<InitiativeState | undefined>();

  readonly initiativeRequested =
    output<void>();

  protected requestInitiative(): void {
    this.initiativeRequested.emit();
  }
}

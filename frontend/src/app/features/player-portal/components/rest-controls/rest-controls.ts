import {
  Component,
  input,
  output,
} from '@angular/core';

import {
  RestRequest,
  RestType,
} from '@core/models/rest-request.model';

@Component({
  selector: 'app-rest-controls',
  imports: [],
  templateUrl: './rest-controls.html',
  styleUrl: './rest-controls.scss',
})
export class RestControls {
  readonly request =
    input<RestRequest | undefined>();

  readonly feedback =
    input<string | null>(null);

  readonly restRequested =
    output<RestType>();

  protected requestRest(type: RestType): void {
    if (this.request()) {
      return;
    }

    this.restRequested.emit(type);
  }

  protected restLabel(type: RestType): string {
    return type === 'short-rest'
      ? 'Repos court'
      : 'Repos long';
  }
}

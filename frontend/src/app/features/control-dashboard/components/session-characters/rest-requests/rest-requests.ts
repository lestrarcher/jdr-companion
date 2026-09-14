import {
  Component,
  input,
  output,
} from '@angular/core';

import {
  RestRequestApiResponse,
} from '@core/services/rest-request-api.service';

export interface RestRequestResolution {
  requestId: number;
  approved: boolean;
}

@Component({
  selector: 'app-rest-requests',
  imports: [],
  templateUrl: './rest-requests.html',
  styleUrl: './rest-requests.scss',
})
export class RestRequests {
  readonly requests =
    input.required<RestRequestApiResponse[]>();

  readonly resolvingRequestId =
    input<number | null>(null);

  readonly error =
    input<string | null>(null);

  readonly requestResolved =
    output<RestRequestResolution>();

  protected resolve(
    requestId: number,
    approved: boolean,
  ): void {
    if (
      this.resolvingRequestId() !== null
    ) {
      return;
    }

    this.requestResolved.emit({
      requestId,
      approved,
    });
  }

  protected restTypeLabel(
    type: 'short-rest' | 'long-rest',
  ): string {
    return type === 'short-rest'
      ? 'Repos court'
      : 'Repos long';
  }
}

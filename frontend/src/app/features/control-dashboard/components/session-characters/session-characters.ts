import {
  Component,
  computed,
  input,
  output,
} from '@angular/core';

import {
  ImportedCharacterResult,
} from '@core/services/campaign-bootstrap.service';
import {
  RestRequestApiResponse,
} from '@core/services/rest-request-api.service';

export interface RestRequestResolution {
  requestId: number;
  approved: boolean;
}

@Component({
  selector: 'app-session-characters',
  imports: [],
  templateUrl: './session-characters.html',
  styleUrl: './session-characters.scss',
})
export class SessionCharacters {
  readonly campaignId =
    input.required<number>();

  readonly sessionId =
    input.required<number>();

  readonly characters =
    input.required<ImportedCharacterResult[]>();

  readonly initializationRunning =
    input(false);

  readonly initializationError =
    input<string | null>(null);

  readonly restRequests =
    input.required<RestRequestApiResponse[]>();

  readonly resolvingRestRequestId =
    input<number | null>(null);

  readonly restRequestError =
    input<string | null>(null);

  readonly charactersInitialized =
    output<void>();

  readonly restRequestResolved =
    output<RestRequestResolution>();

  protected readonly hasCharacters =
    computed(
      () => this.characters().length > 0,
    );

  protected initializeCharacters(): void {
    if (this.initializationRunning()) {
      return;
    }

    this.charactersInitialized.emit();
  }

  protected resolveRestRequest(
    requestId: number,
    approved: boolean,
  ): void {
    if (
      this.resolvingRestRequestId() !==
      null
    ) {
      return;
    }

    this.restRequestResolved.emit({
      requestId,
      approved,
    });
  }

  protected playerPortalUrl(
    accessToken: string | null,
  ): string | null {
    if (!accessToken) {
      return null;
    }

    return [
      '',
      'campaigns',
      this.campaignId(),
      'sessions',
      this.sessionId(),
      'player',
      accessToken,
    ].join('/');
  }

  protected characterInitial(
    characterName: string,
  ): string {
    return characterName
      .trim()
      .charAt(0)
      .toUpperCase();
  }

  protected restTypeLabel(
    type: 'short-rest' | 'long-rest',
  ): string {
    return type === 'short-rest'
      ? 'Repos court'
      : 'Repos long';
  }
}

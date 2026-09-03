import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

import {
  catchError,
  finalize,
  of,
  switchMap,
  timer,
} from 'rxjs';

import {
  RestRequestApiResponse,
  RestRequestApiService,
} from '@core/services/rest-request-api.service';
import {
  CampaignBootstrapService,
  ImportedCharacterResult,
} from '@core/services/campaign-bootstrap.service';
import { GameSessionApiService } from '@core/services/game-session-api.service';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';

import { CampaignMedia } from '@core/models/campaign.model';
import { LiveSessionService } from '@core/services/live-session.service';
import { STRAHD_CAMPAIGN } from '@data/campaigns/strahd.config';

@Component({
  selector: 'app-control-dashboard',
  imports: [ReactiveFormsModule],
  templateUrl: './control-dashboard.html',
  styleUrl: './control-dashboard.scss',
})
export class ControlDashboard {
  private readonly formBuilder = inject(FormBuilder);
  private readonly liveSessionService = inject(LiveSessionService);
  private readonly route = inject(ActivatedRoute);

  protected readonly campaign = STRAHD_CAMPAIGN;
  protected readonly liveState = this.liveSessionService.state;

  private readonly gameSessionApi = inject(
    GameSessionApiService,
  );

  protected readonly sessionStatusUpdateRunning =
    signal(false);

  protected readonly sessionStatusError =
    signal<string | null>(null);

  protected readonly worldForm = this.formBuilder.nonNullable.group({
    day: [1, [Validators.required, Validators.min(1)]],
    dayPeriod: ['', Validators.required],

    weatherId: ['', Validators.required],

    moonId: ['', Validators.required],

    locationName: ['', Validators.required],
    locationSubtitle: [''],
  });

  private readonly campaignBootstrapService = inject(
  CampaignBootstrapService,
);

private readonly backendCampaignId = 1;
private readonly backendSessionId = 1;

protected readonly characterImportRunning = signal(false);
protected readonly characterImportError = signal<string | null>(null);

protected readonly importedCharacters = signal<
  ImportedCharacterResult[]
>([]);

private readonly destroyRef = inject(DestroyRef);

private readonly restRequestApi = inject(
  RestRequestApiService,
);

protected readonly pendingRestRequests =
  signal<RestRequestApiResponse[]>([]);

protected readonly resolvingRestRequestId =
  signal<number | null>(null);

protected readonly restRequestError =
  signal<string | null>(null);

  constructor() {
    const campaignId = this.route.snapshot.paramMap.get('campaignId');
    const sessionId = this.route.snapshot.paramMap.get('sessionId');

    if (!campaignId || !sessionId) {
      throw new Error('Identifiants de campagne ou de session manquants.');
    }

    if (campaignId !== this.campaign.id) {
      throw new Error(`Campagne inconnue : ${campaignId}`);
    }

    this.liveSessionService.initialize(this.campaign, sessionId);


    const state = this.liveState();

    if (state) {
      this.worldForm.patchValue({
        day: state.day ?? 1,
        dayPeriod: state.dayPeriod ?? '',
        weatherId: state.weather?.id ?? '',
        moonId: state.moon?.id ?? '',
        locationName: state.location?.name ?? '',
        locationSubtitle: state.location?.subtitle ?? '',
      });
    }
    this.initializeRestRequestPolling();
  }

  protected updateWorld(): void {
    if (this.worldForm.invalid) {
      this.worldForm.markAllAsTouched();
      return;
    }

    const values = this.worldForm.getRawValue();

    const selectedWeather = this.campaign.weatherStates.find(
      (weather) => weather.id === values.weatherId,
    );

    const selectedMoon = this.campaign.moonPhases.find(
      (moon) => moon.id === values.moonId,
    );

    if (!selectedWeather || !selectedMoon) {
      return;
    }

    this.liveSessionService.updateState({
      day: values.day,
      dayPeriod: values.dayPeriod,

      weather: selectedWeather,

      moon: selectedMoon,

      location: {
        name: values.locationName,
        subtitle: values.locationSubtitle,
      },
    });
  }

  protected displayMedia(media: CampaignMedia): void {
  this.liveSessionService.updateState({
    displayedMedia: {
      id: media.id,
      source: media.source,
      alt: media.alt,
      title: media.title,
      subtitle: media.subtitle,
      fit: media.fit,
    },
  });
}

  protected clearMedia(): void {
    this.liveSessionService.updateState({
      displayedMedia: undefined,
    });
  }

  protected toggleFog(): void {
    const state = this.liveState();

    if (!state) {
      return;
    }

    this.liveSessionService.updateState({
      fogEnabled: !state.fogEnabled,
    });
  }

  protected toggleCinematicMode(): void {
    const state = this.liveState();

    if (!state) {
      return;
    }

    this.liveSessionService.updateState({
      displayMode:
        state.displayMode === 'cinematic'
          ? 'normal'
          : 'cinematic',
    });
  }

  protected readonly sessionStatusLabel = computed(() => {
    switch (this.liveState()?.status) {
      case 'live':
        return 'Session ouverte';

      case 'closed':
        return 'Session terminée';

      default:
        return 'Session en préparation';
    }
  });

  protected openSession(): void {
    this.updateSessionStatus('live');
  }

  protected closeSession(): void {
    this.updateSessionStatus('closed');
  }

  private updateSessionStatus(
    status: 'live' | 'closed',
  ): void {
    if (this.sessionStatusUpdateRunning()) {
      return;
    }

    this.sessionStatusUpdateRunning.set(true);
    this.sessionStatusError.set(null);

    this.gameSessionApi
      .updateStatus(this.backendSessionId, status)
      .pipe(
        finalize(() => {
          this.sessionStatusUpdateRunning.set(false);
        }),
      )
      .subscribe({
        next: (session) => {
          /*
          * Symfony persiste l’état pour les téléphones.
          * BroadcastChannel prévient le display local.
          */
          this.liveSessionService.updateState({
            status: session.status,
          });
        },

        error: (error: any) => {
          console.error(
            'Impossible de modifier le statut de la session.',
            error,
          );

          this.sessionStatusError.set(
            error?.error?.message ??
              error?.error?.detail ??
              'Impossible de modifier le statut de la session.',
          );
        },
      });
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

  this.resolvingRestRequestId.set(
    requestId,
  );

  this.restRequestError.set(null);

  this.restRequestApi
    .resolve(
      requestId,
      approved
        ? 'approved'
        : 'rejected',
    )
    .pipe(
      finalize(() => {
        this.resolvingRestRequestId.set(
          null,
        );
      }),
    )
    .subscribe({
      next: () => {
        this.pendingRestRequests.update(
          (requests) =>
            requests.filter(
              (request) =>
                request.id !== requestId,
            ),
        );
      },

      error: (error: any) => {
        console.error(
          'Impossible de traiter la demande de repos.',
          error,
        );

        this.restRequestError.set(
          error?.error?.message ??
            'La demande de repos n’a pas pu être traitée.',
        );
      },
    });
}
// TODO: remplacer ce polling temporaire par des événements Mercure.
private initializeRestRequestPolling(): void {
  timer(0, 5000)
    .pipe(
      switchMap(() =>
        this.restRequestApi
          .listPending(
            this.backendSessionId,
          )
          .pipe(
            catchError(
              (error: unknown) => {
                console.error(
                  'Impossible de récupérer les demandes de repos.',
                  error,
                );

                return of([]);
              },
            ),
          ),
      ),

      takeUntilDestroyed(
        this.destroyRef,
      ),
    )
    .subscribe((requests) => {
      this.pendingRestRequests.set(
        requests,
      );
    });
}

  protected restTypeLabel(
    type: 'short-rest' | 'long-rest',
  ): string {
    return type === 'short-rest'
      ? 'Repos court'
      : 'Repos long';
  }

  protected synchronizeCharacters(): void {
  if (this.characterImportRunning()) {
    return;
  }

  this.characterImportRunning.set(true);
  this.characterImportError.set(null);

  this.campaignBootstrapService
    .synchronizeCharacters(
      this.campaign,
      this.backendCampaignId,
      this.backendSessionId,
    )
    .pipe(
      finalize(() => {
        this.characterImportRunning.set(false);
      }),
    )
    .subscribe({
      next: (characters) => {
        this.importedCharacters.set(characters);
      },

      error: (error: any) => {
        console.error(
          'Impossible de synchroniser les personnages.',
          error,
        );

        const backendMessage =
          error?.error?.message ??
          error?.error?.detail ??
          error?.message;

        this.characterImportError.set(
          backendMessage
            ? `Synchronisation impossible : ${backendMessage}`
            : 'La synchronisation des personnages a échoué.',
        );
      },
    });
}

protected playerPortalUrl(
  accessToken: string | null,
): string | null {
  if (!accessToken) {
    return null;
  }

  const campaignId =
    this.route.snapshot.paramMap.get('campaignId');

  const sessionId =
    this.route.snapshot.paramMap.get('sessionId');

  if (!campaignId || !sessionId) {
    return null;
  }

  return [
    '',
    'campaigns',
    campaignId,
    'sessions',
    sessionId,
    'player',
    accessToken,
  ].join('/');
}
}

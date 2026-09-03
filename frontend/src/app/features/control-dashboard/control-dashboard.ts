import {
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute } from '@angular/router';

import {
  catchError,
  finalize,
  forkJoin,
  of,
  switchMap,
  timer,
} from 'rxjs';

import { CampaignMedia } from '@core/models/campaign.model';
import {
  CampaignApiService,
} from '@core/services/campaign-api.service';
import {
  CampaignBootstrapService,
  ImportedCharacterResult,
} from '@core/services/campaign-bootstrap.service';
import {
  GameSessionApiResponse,
  GameSessionApiService,
  GameSessionStatus,
} from '@core/services/game-session-api.service';
import { LiveSessionService } from '@core/services/live-session.service';
import {
  RestRequestApiResponse,
  RestRequestApiService,
} from '@core/services/rest-request-api.service';
import { STRAHD_CAMPAIGN } from '@data/campaigns/strahd.config';

import { MediaControls } from './components/media-controls/media-controls';
import { SessionCharacters } from './components/session-characters/session-characters';
import { SessionControls } from './components/session-controls/session-controls';
import {
  WorldControls,
  WorldUpdate,
} from './components/world-controls/world-controls';

@Component({
  selector: 'app-control-dashboard',
  imports: [
    MediaControls,
    SessionCharacters,
    SessionControls,
    WorldControls,
  ],
  templateUrl: './control-dashboard.html',
  styleUrl: './control-dashboard.scss',
})
export class ControlDashboard {
  private readonly route =
    inject(ActivatedRoute);

  private readonly destroyRef =
    inject(DestroyRef);

  private readonly campaignApi =
    inject(CampaignApiService);

  private readonly gameSessionApi =
    inject(GameSessionApiService);

  private readonly liveSessionService =
    inject(LiveSessionService);

  private readonly campaignBootstrapService =
    inject(CampaignBootstrapService);

  private readonly restRequestApi =
    inject(RestRequestApiService);

  protected readonly campaign =
    STRAHD_CAMPAIGN;

  protected readonly liveState =
    this.liveSessionService.state;

  protected readonly backendSession =
    signal<GameSessionApiResponse | null>(
      null,
    );

  protected readonly dashboardLoading =
    signal(true);

  protected readonly dashboardError =
    signal<string | null>(null);

  protected readonly sessionStatusUpdateRunning =
    signal(false);

  protected readonly sessionStatusError =
    signal<string | null>(null);

  protected readonly characterImportRunning =
    signal(false);

  protected readonly characterImportError =
    signal<string | null>(null);

  protected readonly importedCharacters =
    signal<ImportedCharacterResult[]>([]);

  protected readonly pendingRestRequests =
    signal<RestRequestApiResponse[]>([]);

  protected readonly resolvingRestRequestId =
    signal<number | null>(null);

  protected readonly restRequestError =
    signal<string | null>(null);

  protected readonly sessionStatus =
    computed<GameSessionStatus>(() => {
      const status =
        this.liveState()?.status;

      if (
        status === 'live' ||
        status === 'closed'
      ) {
        return status;
      }

      return 'draft';
    });

  protected readonly displayUrl =
    computed(() => [
      '',
      'campaigns',
      this.backendCampaignId,
      'sessions',
      this.backendSessionId,
      'display',
    ].join('/'));

  protected readonly backendCampaignId:
    number;

  protected readonly backendSessionId:
    number;

  constructor() {
    const campaignId = Number(
      this.route.snapshot.paramMap.get(
        'campaignId',
      ),
    );

    const sessionId = Number(
      this.route.snapshot.paramMap.get(
        'sessionId',
      ),
    );

    if (
      !Number.isInteger(campaignId) ||
      campaignId <= 0 ||
      !Number.isInteger(sessionId) ||
      sessionId <= 0
    ) {
      throw new Error(
        'Identifiants de campagne ou de session invalides.',
      );
    }

    this.backendCampaignId = campaignId;
    this.backendSessionId = sessionId;

    this.loadDashboardContext();
  }

  protected updateWorld(
    update: WorldUpdate,
  ): void {
    this.liveSessionService.updateState(
      update,
    );
  }

  protected displayMedia(
    media: CampaignMedia,
  ): void {
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

  protected openSession(): void {
    this.updateSessionStatus('live');
  }

  protected closeSession(): void {
    this.updateSessionStatus('closed');
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
          this.characterImportRunning.set(
            false,
          );
        }),
      )
      .subscribe({
        next: (characters) => {
          this.importedCharacters.set(
            characters,
          );
        },

        error: (error: any) => {
          console.error(
            'Impossible de synchroniser les personnages.',
            error,
          );

          const message =
            error?.error?.message ??
            error?.error?.detail ??
            error?.message;

          this.characterImportError.set(
            message
              ? `Synchronisation impossible : ${message}`
              : 'La synchronisation des personnages a échoué.',
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
              'La demande n’a pas pu être traitée.',
          );
        },
      });
  }

  private loadDashboardContext(): void {
    this.dashboardLoading.set(true);
    this.dashboardError.set(null);

    forkJoin({
      campaigns: this.campaignApi.list(),

      session: this.gameSessionApi.get(
        this.backendSessionId,
      ),
    })
      .pipe(
        finalize(() => {
          this.dashboardLoading.set(
            false,
          );
        }),
      )
      .subscribe({
        next: ({
          campaigns,
          session,
        }) => {
          const backendCampaign =
            campaigns.find(
              (campaign) =>
                campaign.id ===
                this.backendCampaignId,
            );

          if (!backendCampaign) {
            this.dashboardError.set(
              'Campagne introuvable.',
            );

            return;
          }

          if (
            session.campaignId !==
            backendCampaign.id
          ) {
            this.dashboardError.set(
              'Cette session n’appartient pas à cette campagne.',
            );

            return;
          }

          if (
            backendCampaign.slug !==
            'strahd-table-principale'
          ) {
            this.dashboardError.set(
              'Cette campagne ne possède pas encore de configuration visuelle.',
            );

            return;
          }

          this.backendSession.set(session);

          this.liveSessionService.initialize(
            this.campaign,
            String(this.backendSessionId),
          );

          this.liveSessionService.updateState({
            status: session.status,
          });

          this.initializeRestRequestPolling();

          /*
           * L’opération est idempotente :
           * elle recharge aussi les liens existants.
           */
          this.synchronizeCharacters();
        },

        error: (error: any) => {
          console.error(
            'Impossible de charger le dashboard.',
            error,
          );

          this.dashboardError.set(
            error?.error?.message ??
              'Le dashboard n’a pas pu être chargé.',
          );
        },
      });
  }

  private updateSessionStatus(
    status: GameSessionStatus,
  ): void {
    if (
      this.sessionStatusUpdateRunning()
    ) {
      return;
    }

    this.sessionStatusUpdateRunning.set(
      true,
    );

    this.sessionStatusError.set(null);

    this.gameSessionApi
      .updateStatus(
        this.backendSessionId,
        status,
      )
      .pipe(
        finalize(() => {
          this.sessionStatusUpdateRunning.set(
            false,
          );
        }),
      )
      .subscribe({
        next: (session) => {
          this.backendSession.set(session);

          this.liveSessionService.updateState({
            status: session.status,
          });
        },

        error: (error: any) => {
          console.error(
            'Impossible de modifier le statut.',
            error,
          );

          this.sessionStatusError.set(
            error?.error?.message ??
              'Le statut n’a pas pu être modifié.',
          );
        },
      });
  }

  // TODO : remplacer ce polling par Mercure.
  private initializeRestRequestPolling(): void {
    timer(0, 5000)
      .pipe(
        switchMap(() => {
          if (
            this.sessionStatus() !==
            'live'
          ) {
            return of<
              RestRequestApiResponse[]
            >([]);
          }

          return this.restRequestApi
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

                  return of<
                    RestRequestApiResponse[]
                  >([]);
                },
              ),
            );
        }),

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

  protected draftSession(): void {
    this.updateSessionStatus('draft');
  }
}

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
import { CampaignMedia as UploadedCampaignMedia } from '@core/services/media-api.service';
import { MagicItemManager } from './components/magic-item-manager/magic-item-manager';
import { MediaManager } from './components/media-manager/media-manager';
import { FigurePanelMode } from '@core/models/live-session-state.model';
import { CampaignConfig } from '@core/models/campaign.model';
import { CampaignConfigurationRegistryService } from '@core/services/campaign-configuration-registry.service';
import { GameSessionApiResponse, GameSessionApiService, GameSessionStatus } from '@core/services/game-session-api.service';
import { LiveSessionService } from '@core/services/live-session.service';
import { RestRequestApiResponse, RestRequestApiService } from '@core/services/rest-request-api.service';

import { SessionCharacters } from './components/session-characters/session-characters';
import { SessionControls } from './components/session-controls/session-controls';
import { WorldControls, WorldUpdate } from './components/world-controls/world-controls';
import { QuestManager } from './components/quest-manager/quest-manager';
import { CampaignFigureManager } from './components/campaign-figure-manager/campaign-figure-manager';
import { TipManager } from './components/tip-manager/tip-manager';

type DashboardTab =
  | 'staging'
  | 'journal'
  | 'figures'
  | 'characters'
  | 'items';

@Component({
  selector: 'app-control-dashboard',
  imports: [
    SessionCharacters,
    SessionControls,
    WorldControls,
    MediaManager,
    QuestManager,
    CampaignFigureManager,
    TipManager,
    MagicItemManager,
  ],
  templateUrl: './control-dashboard.html',
  styleUrl: './control-dashboard.scss',
})
export class ControlDashboard {
  private readonly route = inject(ActivatedRoute);

  private readonly destroyRef = inject(DestroyRef);

  private readonly campaignConfigurationRegistry = inject(CampaignConfigurationRegistryService);

  private readonly gameSessionApi = inject(GameSessionApiService);

  private readonly liveSessionService = inject(LiveSessionService);

  private readonly restRequestApi = inject(RestRequestApiService);

  protected campaign: CampaignConfig | null =
    null;

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

  protected readonly pendingRestRequests =
    signal<RestRequestApiResponse[]>([]);

  protected readonly resolvingRestRequestId =
    signal<number | null>(null);

  protected readonly restRequestError =
    signal<string | null>(null);

  protected readonly activeTab =
    signal<DashboardTab>('staging');

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

  protected selectTab(tab: DashboardTab): void {
    this.activeTab.set(tab);
  }

  protected updateFigurePanelMode(
    mode: FigurePanelMode,
  ): void {
    this.liveSessionService.updateState({
      figurePanelMode: mode,
    });
  }

  protected displayUploadedMedia(
    media: UploadedCampaignMedia,
  ): void {
    this.liveSessionService.updateState({
      displayedMedia: {
        id: `uploaded-media-${media.id}`,
        source: media.url,
        alt:
          media.title ??
          media.originalName,
        title:
          media.title ??
          undefined,
        subtitle: undefined,
        fit: 'contain',
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
      campaignContext:
        this.campaignConfigurationRegistry.getCampaign(
          this.backendCampaignId,
        ),

      session: this.gameSessionApi.get(
        this.backendSessionId,
      ),
    })
      .pipe(
        finalize(() => {
          this.dashboardLoading.set(false);
        }),
      )
      .subscribe({
        next: ({
          campaignContext,
          session,
        }) => {
          const backendCampaign =
            campaignContext.campaign;

          if (
            session.campaignId !==
            backendCampaign.id
          ) {
            this.dashboardError.set(
              'Cette session n’appartient pas à cette campagne.',
            );

            return;
          }

          /*
          * La configuration visuelle est désormais
          * choisie avec configurationKey et non le slug.
          */
          this.campaign =
            campaignContext.configuration;

          this.backendSession.set(session);

          this.liveSessionService.initialize(
            this.campaign,
            String(this.backendSessionId),
          );

          this.liveSessionService.updateState({
            status: session.status,
          });

          this.initializeRestRequestPolling();
        },

        error: (error: any) => {
          console.error(
            'Impossible de charger le dashboard.',
            error,
          );

          this.dashboardError.set(
            error?.error?.message ??
            error?.message ??
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

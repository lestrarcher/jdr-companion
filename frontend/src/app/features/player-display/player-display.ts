import {
  Component,
  inject,
  signal,
} from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import {
  finalize,
  forkJoin,
} from 'rxjs';

import { CampaignConfig } from '@core/models/campaign.model';
import { CampaignConfigurationRegistryService } from '@core/services/campaign-configuration-registry.service';
import {
  GameSessionApiService,
  GameSessionDisplayResponse,
} from '@core/services/game-session-api.service';
import { MoonState } from '@core/models/live-session-state.model';

import { MoonPhaseApiService } from '@core/services/moon-phase-api.service';
import { LiveSessionService } from '@core/services/live-session.service';
import { AmbientFog } from '@shared/components/ambient-fog/ambient-fog';
import { InitiativeCinematic } from './components/initiative-cinematic/initiative-cinematic';
import { MainDisplay } from './components/main-display/main-display';
import { MemorialPanel } from './components/memorial-panel/memorial-panel';
import { QuestsPanel } from './components/quests-panel/quests-panel';
import { TipBar } from './components/tip-bar/tip-bar';
import { WorldHeader } from './components/world-header/world-header';

@Component({
  selector: 'app-player-display',
  imports: [
    WorldHeader,
    MainDisplay,
    QuestsPanel,
    MemorialPanel,
    TipBar,
    AmbientFog,
    InitiativeCinematic,
  ],
  templateUrl: './player-display.html',
  styleUrl: './player-display.scss',
})
export class PlayerDisplay {
  private readonly route =
    inject(ActivatedRoute);

  protected readonly campaignId: number;

  private readonly moonPhaseApi = inject(MoonPhaseApiService);
  private readonly gameSessionApi =
    inject(GameSessionApiService);

  private readonly campaignConfigurationRegistry =
    inject(CampaignConfigurationRegistryService);

  private readonly liveSessionService =
    inject(LiveSessionService);

  protected campaign!: CampaignConfig;

  protected readonly liveState =
    this.liveSessionService.state;

  protected readonly loading = signal(true);

  protected readonly error =
    signal<string | null>(null);

  constructor() {
    const campaignId = Number(
      this.route.snapshot.paramMap.get(
        'campaignId',
      ),
    );

    this.campaignId = campaignId;

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
      this.error.set(
        'Identifiants de session invalides.',
      );

      this.loading.set(false);
      return;
    }

    this.loadSession(
      campaignId,
      sessionId,
    );
  }

  private loadSession(
    campaignId: number,
    sessionId: number,
  ): void {
    this.loading.set(true);
    this.error.set(null);

    forkJoin({
      campaignContext:
        this.campaignConfigurationRegistry
          .getCampaign(campaignId),

      session: this.gameSessionApi.getDisplay(sessionId),
      moonPhases: this.moonPhaseApi.list(),
    })
      .pipe(
        finalize(() => {
          this.loading.set(false);
        }),
      )
      .subscribe({
        next: ({
          campaignContext,
          session,
          moonPhases,
        }) => {
          if (
            session.campaignId !==
            campaignContext.campaign.id
          ) {
            this.error.set(
              'Cette session n’appartient pas à cette campagne.',
            );

            return;
          }

          this.campaign = campaignContext.configuration;

          /*
           * Le service est initialisé même lorsque
           * la session est en draft afin d’écouter
           * le BroadcastChannel du control.
           */
          this.liveSessionService.initialize(
            this.campaign,
            String(sessionId),
          );

          const weather = session.weather
            ? {
                id: session.weather.key,
                label: session.weather.label,
                imageUrl:
                  session.weather.imageUrl ?? '',
                alt:
                  session.weather.alt
                  ?? session.weather.label,
              }
            : undefined;

          const moon =
            session.moonPhase
              ? moonPhases.find(
                  candidate =>
                    candidate.id ===
                    session.moonPhase,
                )
              : undefined;

          this.liveSessionService.updateState({
            status: session.status,

            weather,
            showWeather: session.showWeather,

            moon,
            showMoonPhase:
              session.showMoonPhase,
          });
        },

        error: (error: any) => {
          console.error(
            'Impossible de charger le display.',
            error,
          );

          this.error.set(
            error?.error?.message ??
              error?.message ??
              'Le display n’a pas pu être chargé.',
          );
        },
      });
  }
}

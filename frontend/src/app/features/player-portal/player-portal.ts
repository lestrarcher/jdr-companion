import {
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import {
  CharacterWalletComponent,
} from './components/character-wallet/character-wallet';
import { CharacterMagicItems } from './components/character-magic-items/character-magic-items';

import {
  EMPTY,
  Subject,
  catchError,
  debounceTime,
  finalize,
  of,
  switchMap,
  tap,
  timer,
} from 'rxjs';

import {
  CharacterSessionStatePayload,
  characterProfileToCharacter,
  toCharacterSessionStatePayload,
} from '@core/mappers/character-api.mapper';
import { Character } from '@core/models/character.model';
import { RestType } from '@core/models/rest-request.model';
import { CharacterSessionStateApiService } from '@core/services/character-session-state-api.service';
import { CharacterStateService } from '@core/services/character-state.service';
import {
  RestRequestApiResponse,
  RestRequestApiService,
} from '@core/services/rest-request-api.service';
import { CampaignConfig } from '@core/models/campaign.model';
import {
  CampaignConfigurationRegistryService,
} from '@core/services/campaign-configuration-registry.service';
import {
  CharacterProgressions,
  ProgressionChange,
  ProgressionResourceChange,
} from './components/character-progressions/character-progressions';
import { CharacterResources } from './components/character-resources/character-resources';
import { CharacterStoredValues } from './components/character-stored-values/character-stored-values';
import { CharacterVitals } from './components/character-vitals/character-vitals';
import { RestControls } from './components/rest-controls/rest-controls';

type SessionStatus =
  | 'draft'
  | 'live'
  | 'closed';

type SaveStatus =
  | 'idle'
  | 'saving'
  | 'saved'
  | 'error';

type PlayerPortalTab =
  | 'status'
  | 'possessions';

@Component({
  selector: 'app-player-portal',
  imports: [
    CharacterProgressions,
    CharacterResources,
    CharacterStoredValues,
    CharacterVitals,
    CharacterWalletComponent,
    CharacterMagicItems,
    RestControls,
    RouterLink,
  ],
  templateUrl: './player-portal.html',
  styleUrl: './player-portal.scss',
})
export class PlayerPortal {
  private readonly route = inject(ActivatedRoute);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly accessToken = this.route.snapshot.paramMap.get('accessToken') ?? '';

  private readonly characterStateService = inject(CharacterStateService);
  private readonly characterSessionStateApi = inject(CharacterSessionStateApiService);
  private readonly restRequestApi = inject(RestRequestApiService);
  private readonly campaignConfigurationRegistry = inject(CampaignConfigurationRegistryService);

  protected readonly characterState = this.characterStateService.character;

  protected readonly campaign = signal<CampaignConfig | null>(null);
  protected readonly character = signal<Character | null>(null);
  protected readonly sessionStatus = signal<SessionStatus | null>(null);
  protected readonly loading = signal(true);
  protected readonly loadError = signal<string | null>(null);
  protected readonly restFeedback = signal<string | null>(null);
  protected readonly saveStatus = signal<SaveStatus>('idle');
  protected readonly levelUpAllowed = signal(false);
  private readonly latestRestRequest = signal<RestRequestApiResponse | null>(null);
  private readonly restRequestSubmitting = signal(false);
  private readonly handledRestRequest = signal<string | null>(null);

  private readonly remoteSynchronization = new Subject<CharacterSessionStatePayload>();

  private readonly remoteSynchronizationEnabled = signal(false);

  private readonly campaignId =
    this.route.snapshot.paramMap.get(
      'campaignId',
    ) ?? '';

  private readonly sessionId =
    this.route.snapshot.paramMap.get(
      'sessionId',
    ) ?? '';
  protected readonly activeTab = signal<PlayerPortalTab>('status');
  protected readonly sortedResources = computed(() => {
    const character = this.characterState();

    if (!character) {
      return [];
    }

    return character.resources
      .filter(
        (resource) =>
          resource.hiddenFromTracker !== true,
      )
      .filter((resource) => {
        const condition =
          resource.unlockCondition;

        if (!condition) {
          return true;
        }

        const progression =
          character.progressions?.find(
            (candidate) =>
              candidate.id ===
              condition.progressionId,
          );

        return progression
          ? progression.currentValue >=
              condition.minimumValue
          : false;
      })
      .sort(
        (first, second) =>
          first.displayOrder -
          second.displayOrder,
      );
  });
  protected readonly storedValueResources = computed(() =>
    this.sortedResources().filter(
      (resource) =>
        resource.storedValuesConfig !==
        undefined,
    ),
  );
  protected readonly pendingRestRequest = computed(() => {
    const request =
      this.latestRestRequest();

    return request?.status === 'pending'
      ? request
      : undefined;
  });
  protected readonly closedMessage = computed(() => {
    return this.sessionStatus() === 'closed'
      ? 'Cette session est terminée.'
      : 'La session n’est pas encore ouverte.';
  });

  constructor() {
    if (
      !this.campaignId ||
      !this.sessionId ||
      !this.accessToken
    ) {
      this.loadError.set(
        'Le lien joueur est incomplet.',
      );

      this.loading.set(false);
      return;
    }

    this.initializeRemoteSynchronization();
    this.initializeRestRequestPolling();
    this.loadCharacter();

    effect(() => {
      const character =
        this.characterState();

      if (
        !this.remoteSynchronizationEnabled() ||
        !character
      ) {
        return;
      }

      this.remoteSynchronization.next(
        toCharacterSessionStatePayload(
          character,
        ),
      );
    });
  }

  protected selectTab(
    tab: PlayerPortalTab,
  ): void {
    if (this.pendingRestRequest()) {
      return;
    }

    this.activeTab.set(tab);
  }

  protected handleProgressionChange(
    event: ProgressionChange,
  ): void {
    this.characterStateService
      .adjustProgression(
        event.progressionId,
        event.change,
      );
  }

  protected handleProgressionResourceChange(
    event: ProgressionResourceChange,
  ): void {
    this.characterStateService.adjustResource(
      event.resourceId,
      event.change,
    );
  }

  protected requestRest(
    type: RestType,
  ): void {
    if (
      !this.character() ||
      this.sessionStatus() !== 'live' ||
      this.pendingRestRequest() ||
      this.restRequestSubmitting()
    ) {
      return;
    }

    this.restFeedback.set(null);
    this.restRequestSubmitting.set(true);

    this.restRequestApi
      .create(this.accessToken, type)
      .pipe(
        finalize(() => {
          this.restRequestSubmitting.set(false);
        }),
      )
      .subscribe({
        next: (request) => {
          this.latestRestRequest.set(request);
        },

        error: (error: any) => {
          console.error(
            'Impossible de demander le repos.',
            error,
          );

          this.showRestFeedback(
            error?.error?.message ??
              'La demande de repos a échoué.',
          );
        },
      });
  }

  private loadCharacter(
    displayLoading = true,
  ): void {
    if (displayLoading) {
      this.loading.set(true);
      this.loadError.set(null);
    }

    this.remoteSynchronizationEnabled
      .set(false);

    this.characterSessionStateApi
      .getByAccessToken(
        this.accessToken,
      )
      .subscribe({
        next: (response) => {
          if (
            String(response.campaign.id) !==
            this.campaignId
          ) {
            this.loadError.set(
              'Ce personnage n’appartient pas à cette campagne.',
            );

            this.loading.set(false);
            return;
          }

          if (
            String(response.session.id) !==
            this.sessionId
          ) {
            this.loadError.set(
              'Ce personnage n’appartient pas à cette session.',
            );

            this.loading.set(false);
            return;
          }

          let campaign: CampaignConfig;

          try {
            campaign =
              this.campaignConfigurationRegistry
                .getConfiguration(
                  response.campaign
                    .configurationKey,
                );
          } catch (error: unknown) {
            this.loadError.set(
              error instanceof Error
                ? error.message
                : 'La configuration de cette campagne est indisponible.',
            );

            this.loading.set(false);
            return;
          }

          const loadedCharacter =
            characterProfileToCharacter(
              response.character,
              response.state,
            );


          this.campaign.set(campaign);
          this.character.set(loadedCharacter);

          this.sessionStatus.set(response.session.status);
          this.levelUpAllowed.set(response.levelUpAllowed);

          this.characterStateService.initialize(
            campaign.id,
            loadedCharacter,
            false,
          );

          this.remoteSynchronizationEnabled.set(true);

          this.loading.set(false);
        },

        error: (error: any) => {
          console.error(
            'Impossible de charger le personnage.',
            error,
          );

          if (displayLoading) {
            this.loadError.set(
              error?.error?.message ??
                'Ce lien joueur est invalide ou indisponible.',
            );
          } else {
            this.showRestFeedback(
              'Le repos a été accepté, mais les nouvelles valeurs n’ont pas pu être chargées.',
            );
          }

          this.loading.set(false);
        },
      });
  }

  private initializeRemoteSynchronization(): void {
    this.remoteSynchronization
      .pipe(
        debounceTime(400),

        tap(() => {
          this.saveStatus.set(
            'saving',
          );
        }),

        switchMap((state) =>
          this.characterSessionStateApi
            .updateByAccessToken(
              this.accessToken,
              state,
            )
            .pipe(
              catchError(
                (error: unknown) => {
                  console.error(
                    'Impossible d’enregistrer le personnage.',
                    error,
                  );

                  this.saveStatus.set(
                    'error',
                  );

                  return EMPTY;
                },
              ),
            ),
        ),

        takeUntilDestroyed(
          this.destroyRef,
        ),
      )
      .subscribe(() => {
        this.saveStatus.set(
          'saved',
        );
      });
  }
// TODO: remplacer ce polling temporaire par des événements Mercure.
  private initializeRestRequestPolling(): void {
    timer(0, 5000)
      .pipe(
        switchMap(() => {
          if (this.sessionStatus() !== 'live') {
            this.latestRestRequest.set(null);

            return of(null);
          }

          return this.restRequestApi
            .getLatest(this.accessToken)
            .pipe(
              catchError((error: unknown) => {
                console.error(
                  'Impossible de vérifier la demande de repos.',
                  error,
                );

                return of(null);
              }),
            );
        }),

        takeUntilDestroyed(
          this.destroyRef,
        ),
      )
      .subscribe((request) => {
        this.handleRestRequestUpdate(
          request,
        );
      });
  }

  private handleRestRequestUpdate(
    request: RestRequestApiResponse | null,
  ): void {
    this.latestRestRequest.set(request);

    if (
      !request ||
      request.status === 'pending'
    ) {
      return;
    }

    const requestVersion =
      `${request.id}:${request.status}`;

    if (
      this.handledRestRequest() ===
      requestVersion
    ) {
      return;
    }

    this.handledRestRequest.set(
      requestVersion,
    );

    if (request.status === 'approved') {
      this.showRestFeedback(
        request.type === 'short-rest'
          ? 'Repos court accordé par le MJ.'
          : 'Repos long accordé par le MJ.',
      );

      this.loadCharacter(false);
      return;
    }

    this.showRestFeedback(
      'Le MJ a refusé le repos.',
    );
  }

  private showRestFeedback(
    message: string,
  ): void {
    this.restFeedback.set(message);

    window.setTimeout(() => {
      this.restFeedback.set(null);
    }, 5000);
  }
}

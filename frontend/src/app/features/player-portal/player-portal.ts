import { Component, DestroyRef, computed, effect, inject, signal, untracked, viewChild } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { CharacterWalletComponent } from './components/character-wallet/character-wallet';
import { CharacterMagicItems } from './components/character-magic-items/character-magic-items';

import { EMPTY, Subject, catchError, concatMap, map, takeUntil, exhaustMap, finalize, of, switchMap, tap, timer } from 'rxjs';

import { Character } from '@core/models/character.model';
import { CharacterAbilityScore, CharacterTraining, CharacterActionSummary, CharacterFeatSummary, CharacterFeatureSummary } from '@core/services/character-api.service';

import { CharacterActionsState, CharacterSessionStatePayload, characterProfileToCharacter, toCharacterSessionStatePayload } from '@core/mappers/character-api.mapper';
import { ActionPreparationModal } from './components/action-preparation-modal/action-preparation-modal';
import { CharacterSessionStateApiService, PlayerSpell } from '@core/services/character-session-state-api.service';
import type { SpellCastResponse } from '@core/models/spell-cast.model';
import { RestType } from '@core/models/rest-request.model';
import { CharacterStateService } from '@core/services/character-state.service';
import { RestRequestApiResponse, RestRequestApiService } from '@core/services/rest-request-api.service';
import { CampaignConfig } from '@core/models/campaign.model';
import { CampaignConfigurationRegistryService } from '@core/services/campaign-configuration-registry.service';
import { CharacterProgressions, ProgressionChange, ProgressionResourceChange } from './components/character-progressions/character-progressions';
import { CharacterResources } from './components/character-resources/character-resources';
import { MagicalTinkeringNotes } from './components/magical-tinkering-notes/magical-tinkering-notes';
import { CharacterStoredValues } from './components/character-stored-values/character-stored-values';
import { CharacterVitals } from './components/character-vitals/character-vitals';
import { RestControls } from './components/rest-controls/rest-controls';
import { AidActionModal, AidActionPayload, AidSpellSlot, AidTarget } from './components/aid-action-modal/aid-action-modal';
import { HeroesFeastActionModal, HeroesFeastActionPayload } from './components/heroes-feast-action-modal/heroes-feast-action-modal';
import { FlexibleCastingActionModal, FlexibleCastingPayload } from './components/flexible-casting-action-modal/flexible-casting-action-modal';
import { CharacterStatistics } from './components/character-statistics/character-statistics';
import { PlayerSpells } from './components/player-spells/player-spells';
import { CharacterFeatures } from './components/character-features/character-features';
import { ArcaneRecoveryActionModal, ArcaneRecoveryPayload } from './components/arcane-recovery-action-modal/arcane-recovery-action-modal';

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
  | 'overview'
  | 'status'
  | 'statistics'
  | 'features'
  | 'spells'
  | 'possessions';

@Component({
  selector: 'app-player-portal',
  imports: [
    MagicalTinkeringNotes,
    CharacterProgressions,
    CharacterResources,
    CharacterStoredValues,
    CharacterVitals,
    CharacterWalletComponent,
    CharacterMagicItems,
    RestControls,
    ActionPreparationModal,
    RouterLink,
    AidActionModal,
    HeroesFeastActionModal,
    FlexibleCastingActionModal,
    CharacterFeatures,
    CharacterStatistics,
    PlayerSpells,
    ArcaneRecoveryActionModal,
  ],
  templateUrl: './player-portal.html',
  styleUrl: './player-portal.scss',
})
export class PlayerPortal {
  private readonly spellPanel = viewChild(PlayerSpells);
  canLeavePreparation(): boolean { return this.spellPanel()?.canLeavePreparation() ?? true; }
  // Dependencies and route context
  private readonly http = inject(HttpClient);
  private readonly route = inject(ActivatedRoute);
  private readonly destroyRef = inject(DestroyRef);
  private readonly characterStateService = inject(CharacterStateService);
  private readonly characterSessionStateApi = inject(CharacterSessionStateApiService);
  private readonly restRequestApi = inject(RestRequestApiService);
  private readonly campaignConfigurationRegistry = inject(CampaignConfigurationRegistryService);

  protected readonly accessToken = this.route.snapshot.paramMap.get('accessToken') ?? '';
  private readonly campaignId = this.route.snapshot.paramMap.get('campaignId') ?? '';
  private readonly sessionId = this.route.snapshot.paramMap.get('sessionId') ?? '';

  // Character and session
  protected readonly characterState = this.characterStateService.character;
  protected readonly campaign = signal<CampaignConfig | null>(null);
  protected readonly character = signal<Character | null>(null);
  protected readonly playerSpells = signal<PlayerSpell[]>([]);
  protected readonly sessionStatus = signal<SessionStatus | null>(null);
  protected readonly spellCasting = signal(false);
  protected readonly canEdit = computed(() => this.sessionStatus() === 'live' && !this.spellCasting());
  protected readonly levelUpAllowed = signal(false);
  protected readonly loading = signal(true);
  protected readonly loadError = signal<string | null>(null);
  protected readonly saveStatus = signal<SaveStatus>('idle');
  protected readonly saveError = signal<string | null>(null);
  protected readonly inspiration = signal(false);
  protected readonly inspirationSaving = signal(false);
  protected readonly activeTab = signal<PlayerPortalTab>(this.route.snapshot.queryParamMap?.get('tab') === 'spells'
    ? 'spells' : typeof window !== 'undefined' && window.matchMedia('(min-width: 900px)').matches ? 'overview' : 'status');
  protected readonly spellsVisited = signal(this.activeTab() === 'spells');

  // Features and actions
  protected readonly abilities = signal<CharacterAbilityScore[]>([]);
  protected readonly training = signal<CharacterTraining | null>(null);
  protected readonly proficiencyBonus = signal(0);
  protected readonly orderedAbilities = computed(() => {
    const order = ['strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma'];
    return [...this.abilities()].sort((a, b) => order.indexOf(a.ability) - order.indexOf(b.ability));
  });
  protected readonly hasProgressions = computed(() => (this.characterState()?.progressions?.length ?? 0) > 0);
  protected readonly desktopView = signal(typeof window !== 'undefined' && window.matchMedia('(min-width: 900px)').matches);
  protected readonly features = signal<CharacterFeatureSummary[]>([]);
  protected readonly feats = signal<CharacterFeatSummary[]>([]);
  protected readonly visibleFeatures = computed(() => this.features().filter(feature => feature.visible));

  protected readonly actions = signal<CharacterActionSummary[]>([]);
  protected readonly characterActions = signal<CharacterActionsState | null>(null);
  protected readonly activeAction = signal<CharacterActionSummary | null>(null);
  protected readonly flexibleCastingAction = computed(() => this.actions().find(action => action.handlerType === 'flexible-casting'));
  protected readonly arcaneRecoveryResource = computed(() => this.character()?.resources.find(resource => resource.id === 'arcane-recovery'));
  protected readonly arcaneRecoveryAction = computed(() => this.actions().find(action => action.handlerType === 'arcane-recovery' && (this.arcaneRecoveryResource()?.currentValue ?? 0) > 0));
  protected readonly preparationActions = computed(() => this.actions().filter(action => action.requiresPreparation));
  protected readonly preparationPending = computed(() => this.characterActions()?.preparationPending === true && this.preparationActions().length > 0);
  protected readonly preparedActions = computed(() => {
    const prepared = this.characterActions()?.prepared ?? [];
    return this.actions().filter(action => prepared.includes(action.slug));
  });

  // Resources and trackers
  protected readonly sortedResources = computed(() => {
    const character = this.characterState();
    if (!character) return [];

    return character.resources
      .filter(resource => resource.hiddenFromTracker !== true)
      .filter(resource => {
        const condition = resource.unlockCondition;
        if (!condition) return true;

        const progression = character.progressions?.find(candidate => candidate.id === condition.progressionId);
        return progression ? progression.currentValue >= condition.minimumValue : false;
      })
      .sort((first, second) => first.displayOrder - second.displayOrder);
  });
  protected readonly storedValueResources = computed(() => this.sortedResources().filter(resource => resource.storedValuesConfig !== undefined));
  protected readonly aidSpellSlots = computed<AidSpellSlot[]>(() =>
    this.characterState()
      ?.resources
      .filter(resource => resource.id.startsWith('spell-slot-') && resource.currentValue > 0)
      .map(resource => ({ level: Number(resource.id.replace('spell-slot-', '')), current: resource.currentValue }))
      .filter(slot => Number.isInteger(slot.level) && slot.level >= 2)
      .sort((a, b) => a.level - b.level)
    ?? [],
  );

  // Rest workflow
  protected readonly restFeedback = signal<string | null>(null);
  private readonly latestRestRequest = signal<RestRequestApiResponse | null>(null);
  private readonly restRequestSubmitting = signal(false);
  private readonly handledRestRequest = signal<string | null>(null);
  protected readonly pendingRestRequest = computed(() => {
    const request = this.latestRestRequest();
    return request?.status === 'pending' ? request : undefined;
  });
  protected readonly closedMessage = computed(() => this.sessionStatus() === 'closed' ? 'Cette session est terminée.' : 'La session n’est pas encore ouverte.');

  // Action workflow
  protected readonly preparationSaving = signal(false);
  protected readonly preparationError = signal<string | null>(null);
  protected readonly aidTargets = signal<AidTarget[]>([]);
  protected readonly flexibleCastingSaving = signal(false);
  protected readonly flexibleCastingError = signal<string | null>(null);
  protected readonly arcaneRecoverySaving = signal(false);
  protected readonly arcaneRecoveryError = signal<string | null>(null);

  // Remote synchronization
  private readonly remoteSynchronization = new Subject<{
    state: CharacterSessionStatePayload;
    revision: number;
    generation: number;
  } | null>();
  private readonly sessionClosed = new Subject<void>();
  private readonly remoteSynchronizationEnabled = signal(false);
  private lastQueuedRevision = 0;
  protected serverRevision = 0;
  protected readonly tinkeringNotes = signal('');
  protected readonly tinkeringResources = computed(() => this.sortedResources().filter(resource => resource.id === 'magical-tinkering'));
  protected readonly hasTinkering = computed(() => this.features().some(feature => feature.slug === 'magical-tinkering'));
  protected refreshTinkering(): void { this.loadCharacter(false); }
  protected refreshSpells(): void { this.loadCharacter(false); }
  protected adoptSpellCast(response: SpellCastResponse): void {
    // Invalidate older GETs/queued snapshots. Applying server state never increases
    // the local edit revision and must not enqueue a PATCH for this consumption.
    if (response.revision < this.serverRevision) return;
    this.loadGeneration++;
    this.remoteSynchronization.next(null);
    this.serverRevision = response.revision;
    this.setSessionStatus(response.session.status);
    const character = characterProfileToCharacter(response.character, response.state);
    this.characterStateService.applyServerState(character);
    this.lastQueuedRevision = this.characterStateService.revision();
    this.character.set(character);
    this.playerSpells.set(response.spells);
    this.inspiration.set(response.state.inspiration === true);
    this.tinkeringNotes.set(response.state.magicalTinkeringNotes ?? '');
    this.abilities.set(response.character.abilities);
    this.training.set(response.character.training ?? null);
    this.proficiencyBonus.set(response.character.proficiencyBonus);
    this.features.set(response.character.features);
    this.feats.set(response.character.feats);
    this.actions.set(response.character.actions ?? []);
    this.characterActions.set(response.state.characterActions ?? null);
    this.levelUpAllowed.set(response.levelUpAllowed);
    this.saveStatus.set('saved');
    this.saveError.set(null);
  }
  private loadGeneration = 0;
  private refreshPending = false;

  private setSessionStatus(status: SessionStatus): void {
    if (status !== this.sessionStatus()) {
      // Invalidate queued snapshots across either transition, including a quick reopen.
      this.loadGeneration++;
      this.remoteSynchronization.next(null);
      this.sessionClosed.next();
      this.lastQueuedRevision = this.characterStateService.revision();
      this.activeAction.set(null);
      this.saveStatus.set('idle');
    }
    this.sessionStatus.set(status);
  }

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

    if (typeof window !== 'undefined') {
      const media = window.matchMedia('(min-width: 900px)');
      const onChange = (event: MediaQueryListEvent) => {
        this.desktopView.set(event.matches);
        if (!event.matches && this.activeTab() === 'overview') this.activeTab.set('status');
      };
      media.addEventListener('change', onChange);
      this.destroyRef.onDestroy(() => media.removeEventListener('change', onChange));
    }
    this.initializeRemoteSynchronization();
    this.initializeRestRequestPolling();
    this.loadCharacter();

    effect(() => {
      const revision = this.characterStateService.revision();
      const character =
        untracked(this.characterState);

      if (
        !this.remoteSynchronizationEnabled() || this.sessionStatus() !== 'live' ||
        !character || revision === this.lastQueuedRevision
      ) {
        return;
      }

      this.lastQueuedRevision = revision;
      this.saveError.set(null);
      this.saveStatus.set('saving');
      this.remoteSynchronization.next({
        state: toCharacterSessionStatePayload(character),
        revision,
        generation: this.loadGeneration,
      });
    });
  }

  protected selectTab(
    tab: PlayerPortalTab,
  ): void {
    if (this.spellCasting()) return;
    if (tab === 'spells') this.spellsVisited.set(true);
    if (this.canEdit() && this.pendingRestRequest()) {
      return;
    }

    this.activeTab.set(tab === 'overview' && !this.desktopView() ? 'status' : tab);
  }

  protected spendInspiration(): void {
    if (!this.inspiration() || this.inspirationSaving() || this.saveStatus() === 'saving'
      || this.sessionStatus() !== 'live' || this.pendingRestRequest() || this.preparationPending()) return;

    // Separate PATCH: a player may spend inspiration, but never grant it.
    const generation = this.loadGeneration;
    this.inspirationSaving.set(true);
    this.saveError.set(null);
    this.saveStatus.set('saving');

    this.http.patch<{ revision: number; state: { inspiration?: boolean } }>(
      `/api/public/characters/${encodeURIComponent(this.accessToken)}`,
      { state: { inspiration: false }, revision: this.serverRevision },
    ).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.inspirationSaving.set(false)))
      .subscribe({
        next: response => {
          if (generation !== this.loadGeneration) return;
          this.serverRevision = response.revision;
          this.inspiration.set(response.state.inspiration === true);
          this.saveStatus.set('saved');
          this.loadCharacter(false);
        },
        error: error => {
          if (generation !== this.loadGeneration) return;
          this.saveStatus.set('error');
          if (error?.status === 409) {
            this.saveError.set('Modification concurrente : données rechargées, recommencez votre modification.');
            this.loadCharacter(false);
          } else {
            this.saveError.set(error?.error?.message ?? 'Impossible de dépenser l’inspiration.');
          }
        },
      });
  }

  protected handleProgressionChange(
    event: ProgressionChange,
  ): void {
    if (!this.canEdit()) return;
    this.characterStateService
      .adjustProgression(
        event.progressionId,
        event.change,
      );
  }

  protected handleProgressionResourceChange(
    event: ProgressionResourceChange,
  ): void {
    if (!this.canEdit()) return;
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
    const generation = ++this.loadGeneration;
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
          if (generation !== this.loadGeneration) return;
          this.serverRevision = response.revision;
          this.inspiration.set(response.state.inspiration === true);
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
          this.tinkeringNotes.set(response.state.magicalTinkeringNotes ?? '');


          this.campaign.set(campaign);
          this.character.set(loadedCharacter);
          this.playerSpells.set(response.spells ?? []);
          this.abilities.set(response.character.abilities);
          this.training.set(response.character.training ?? null);
          this.proficiencyBonus.set(response.character.proficiencyBonus);
          this.features.set(response.character.features);
          this.feats.set(response.character.feats);

          this.actions.set(response.character.actions ?? []);
          this.characterActions.set(
            response.state.characterActions ?? null,
          );

          this.setSessionStatus(response.session.status);
          this.levelUpAllowed.set(response.levelUpAllowed);

          this.characterStateService.initialize(
            campaign.id,
            loadedCharacter,
            false,
            false, // Remote snapshots require their server revision; ignore unversioned broadcasts.
          );
          this.lastQueuedRevision = this.characterStateService.revision();

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
        // A null event cancels the timer without completing the live save stream.
        switchMap(change => change === null || !this.canEdit() ? EMPTY : timer(400).pipe(map(() => change))),

        concatMap(({ state, revision, generation }) => {
          const isCurrent = () =>
            generation === this.loadGeneration &&
            revision === this.characterStateService.revision();

          if (!isCurrent() || this.sessionStatus() !== 'live') return EMPTY;

          return this.characterSessionStateApi
            .updateByAccessToken(
              this.accessToken,
              state,
              this.serverRevision,
            )
            .pipe(
              takeUntil(this.sessionClosed),
              tap((response) => {
                // Even if newer local edits are queued, this successful write
                // becomes their server baseline. Never adopt another load's revision.
                if (generation !== this.loadGeneration) return;
                this.serverRevision = response.revision;
                this.inspiration.set(response.state.inspiration === true);
                if (!isCurrent()) return;

                const character = characterProfileToCharacter(
                  response.character,
                  response.state,
                );
                this.characterStateService.applyServerState(character);
                this.character.set(character);
                this.abilities.set(response.character.abilities);
                this.training.set(response.character.training ?? null);
                this.proficiencyBonus.set(response.character.proficiencyBonus);
                this.features.set(response.character.features);
                this.feats.set(response.character.feats);
                this.training.set(response.character.training ?? null);
                this.proficiencyBonus.set(response.character.proficiencyBonus);
                this.actions.set(response.character.actions ?? []);
                this.characterActions.set(
                  response.state.characterActions ?? null,
                );
                this.setSessionStatus(response.session.status);
                this.levelUpAllowed.set(response.levelUpAllowed);
                this.saveStatus.set('saved');
              }),
              catchError(
                (error: unknown) => {
                  if ((error as { status?: number })?.status === 409 && generation === this.loadGeneration) {
                    this.saveStatus.set('error');
                    this.saveError.set('Modification concurrente : données rechargées, recommencez votre modification.');
                    this.loadCharacter(false);
                    return EMPTY;
                  }
                  console.error(
                    'Impossible d’enregistrer le personnage.',
                    error,
                  );

                  if (isCurrent()) this.saveStatus.set('error');

                  return EMPTY;
                },
              ),
            );
        }),

        takeUntilDestroyed(
          this.destroyRef,
        ),
      )
      .subscribe();
  }

  private refreshCharacterIfChanged(): void {
    if (!this.character() || this.loading() || !this.remoteSynchronizationEnabled()
      || this.refreshPending) return;
    const generation = this.loadGeneration;
    const revision = this.serverRevision;
    const localRevision = this.characterStateService.revision();
    this.refreshPending = true;

    this.characterSessionStateApi.getByAccessToken(this.accessToken).pipe(
      takeUntilDestroyed(this.destroyRef),
      catchError(() => of(null)),
      finalize(() => { this.refreshPending = false; }),
    ).subscribe(response => {
      if (!response || generation !== this.loadGeneration || revision !== this.serverRevision
        || response.revision < this.serverRevision) return;
      const statusChanged = response.session.status !== this.sessionStatus();
      if (response.session.status !== 'closed' && (localRevision !== this.characterStateService.revision() || this.saveStatus() === 'saving')) return;

      // Session status is independent of the character state's revision.
      this.setSessionStatus(response.session.status);
      this.levelUpAllowed.set(response.levelUpAllowed);
      this.playerSpells.set(response.spells ?? []);
      if (!statusChanged && response.revision === this.serverRevision) return;
      this.tinkeringNotes.set(response.state.magicalTinkeringNotes ?? '');

      this.serverRevision = response.revision;
      this.inspiration.set(response.state.inspiration === true);

      this.playerSpells.set(response.spells ?? []);
      const character = characterProfileToCharacter(response.character, response.state);

      this.characterStateService.applyServerState(character);
      this.lastQueuedRevision = this.characterStateService.revision();
      this.character.set(character);
      this.abilities.set(response.character.abilities);
      this.training.set(response.character.training ?? null);
      this.proficiencyBonus.set(response.character.proficiencyBonus);
      this.features.set(response.character.features);
      this.training.set(response.character.training ?? null);
      this.proficiencyBonus.set(response.character.proficiencyBonus);
      this.actions.set(response.character.actions ?? []);
      this.characterActions.set(response.state.characterActions ?? null);
      this.levelUpAllowed.set(response.levelUpAllowed);
    });
  }
// TODO: remplacer ce polling temporaire par des événements Mercure.
private initializeRestRequestPolling(): void {
  timer(0, 5000)
    .pipe(
      exhaustMap(() => {
        this.refreshCharacterIfChanged();

        if (this.sessionStatus() !== 'live') {
          this.latestRestRequest.set(null);
          return of(null);
        }

        return this.restRequestApi.getLatest(this.accessToken)
          .pipe(
            catchError((error: unknown) => {
              console.error('Impossible de vérifier la demande de repos.', error);
              return of(null);
            }),
          );
      }),
      takeUntilDestroyed(this.destroyRef),
    )
    .subscribe(request => {
      this.handleRestRequestUpdate(request);
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

  protected savePreparedActions(
    prepared: string[],
  ): void {
    if (
      !this.canEdit() || !this.preparationPending()
      || this.preparationSaving()
    ) {
      return;
    }

    this.preparationSaving.set(true);
    this.preparationError.set(null);

    this.characterSessionStateApi
      .updatePreparedActions(
        this.accessToken,
        prepared,
        this.serverRevision,
      )
      .pipe(
        finalize(() => {
          this.preparationSaving.set(false);
        }),
      )
      .subscribe({
        next: (response) => {
          this.serverRevision = response.revision;
          this.inspiration.set(response.state.inspiration === true);

          this.actions.set(
            response.character.actions ?? [],
          );

          this.characterActions.set(
            response.state.characterActions ?? null,
          );
        },

        error: (error: any) => {
          if (error?.status === 409) {
            this.preparationError.set(
              'Les données du personnage ont changé. Rechargement…',
            );

            this.loadCharacter(false);
            return;
          }

          this.preparationError.set(
            error?.error?.message
              ?? 'Impossible d’enregistrer la préparation.',
          );
        },
      });
  }

protected openAction(
  action: CharacterActionSummary,
): void {
  if (!this.canEdit()) return;
  switch (action.handlerType) {
    case 'flexible-casting':
      if (!this.flexibleCastingAction() || this.sessionStatus() !== 'live' || this.saveStatus() === 'saving') return;
      this.flexibleCastingError.set(null);
      this.activeAction.set(action);
      break;
    case 'arcane-recovery':
      if (!this.arcaneRecoveryAction() || this.sessionStatus() !== 'live' || this.saveStatus() === 'saving') return;
      this.arcaneRecoveryError.set(null);
      this.activeAction.set(action);
      break;
    case 'aid':
    case 'heroes-feast':
      this.characterSessionStateApi
        .getActionTargets(this.accessToken)
        .pipe(
          takeUntilDestroyed(this.destroyRef),
        )
        .subscribe({
          next: targets => {
            this.aidTargets.set(targets);
            this.activeAction.set(action);
          },
          error: () => {
            this.saveError.set(
              'Impossible de charger les cibles disponibles.',
            );
          },
        });
      break;
  }
}

  protected closeAction(): void {
    if (this.flexibleCastingSaving() || this.arcaneRecoverySaving()) return;
    this.activeAction.set(null);
  }

  protected useFlexibleCasting(payload: FlexibleCastingPayload): void {
    if (!this.flexibleCastingAction() || this.sessionStatus() !== 'live' || this.saveStatus() === 'saving' || this.flexibleCastingSaving()) return;
    this.flexibleCastingSaving.set(true);
    this.flexibleCastingError.set(null);
    this.saveError.set(null);
    this.saveStatus.set('saving');
    const request = payload.mode === 'create'
      ? this.characterSessionStateApi.createFlexibleCastingSlot(this.accessToken, payload.level, this.serverRevision)
      : this.characterSessionStateApi.convertFlexibleCastingSlot(this.accessToken, payload.level, this.serverRevision);
    request.pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.flexibleCastingSaving.set(false))).subscribe({
      next: response => {
        this.serverRevision = response.revision;
          this.inspiration.set(response.state.inspiration === true);
        this.playerSpells.set(response.spells ?? []);
      const character = characterProfileToCharacter(response.character, response.state);
        this.characterStateService.applyServerState(character);
        this.character.set(character);
        this.training.set(response.character.training ?? null);
        this.proficiencyBonus.set(response.character.proficiencyBonus);
        this.actions.set(response.character.actions ?? []);
        this.characterActions.set(response.state.characterActions ?? null);
        this.activeAction.set(null);
        this.saveStatus.set('saved');
      },
      error: error => {
        this.saveStatus.set('error');
        if (error.status === 409) {
          this.activeAction.set(null);
          this.loadCharacter(false);
          this.saveError.set('Le personnage a été modifié ailleurs. Ses données ont été rechargées.');
          return;
        }
        const message = error.error?.message ?? 'Impossible d’utiliser Conversion flexible.';
        this.flexibleCastingError.set(message);
        this.saveError.set(message);
      },
    });
  }

  protected useArcaneRecovery(payload: ArcaneRecoveryPayload): void {
    if (!this.arcaneRecoveryAction() || this.sessionStatus() !== 'live' || this.saveStatus() === 'saving' || this.arcaneRecoverySaving()) return;
    this.arcaneRecoverySaving.set(true);
    this.arcaneRecoveryError.set(null);
    this.saveError.set(null);
    this.saveStatus.set('saving');

    this.characterSessionStateApi.recoverArcaneSlots(this.accessToken, payload.slots, this.serverRevision).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.arcaneRecoverySaving.set(false))).subscribe({
      next: response => {
        this.serverRevision = response.revision;
          this.inspiration.set(response.state.inspiration === true);
        this.playerSpells.set(response.spells ?? []);
      const character = characterProfileToCharacter(response.character, response.state);
        this.characterStateService.applyServerState(character);
        this.character.set(character);
        this.training.set(response.character.training ?? null);
        this.proficiencyBonus.set(response.character.proficiencyBonus);
        this.actions.set(response.character.actions ?? []);
        this.characterActions.set(response.state.characterActions ?? null);
        this.activeAction.set(null);
        this.saveStatus.set('saved');
      },
      error: error => {
        this.saveStatus.set('error');
        if (error.status === 409) {
          this.activeAction.set(null);
          this.loadCharacter(false);
          this.saveError.set('Le personnage a été modifié ailleurs. Ses données ont été rechargées.');
          return;
        }
        const message = error.error?.message ?? 'Impossible d’utiliser Restauration arcanique.';
        this.arcaneRecoveryError.set(message);
        this.saveError.set(message);
      },
    });
  }

  protected useAid(
    payload: AidActionPayload,
  ): void {
    if (this.sessionStatus() !== 'live') {
      return;
    }

    this.saveError.set(null);
    this.saveStatus.set('saving');

    this.characterSessionStateApi
      .useAid(
        this.accessToken,
        payload.spellSlotLevel,
        payload.targetIds,
        this.serverRevision,
      )
      .pipe(
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (response) => {
          this.serverRevision = response.revision;
          this.inspiration.set(response.state.inspiration === true);

          const character =
            characterProfileToCharacter(
              response.character,
              response.state,
            );

          this.characterStateService.applyServerState(
            character,
          );

          this.actions.set(
            response.character.actions ?? [],
          );

          this.characterActions.set(
            response.state.characterActions ?? null,
          );

          this.activeAction.set(null);

          this.saveStatus.set('saved');
        },

        error: (error) => {
          if (error.status === 409) {
            this.loadCharacter(false);

            this.saveError.set(
              'Le personnage a été modifié ailleurs. Ses données ont été rechargées.',
            );

            return;
          }

          this.saveStatus.set('error');

          this.saveError.set(
            error.error?.message
              ?? 'Impossible de lancer Aide.',
          );
        },
      });
  }

  protected useHeroesFeast(payload: HeroesFeastActionPayload): void {
    if (this.sessionStatus() !== 'live') {
      return;
    }

    this.saveError.set(null);
    this.saveStatus.set('saving');

    this.characterSessionStateApi.useHeroesFeast(this.accessToken, payload.hitPointBonus, payload.targetIds, this.serverRevision)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.serverRevision = response.revision;
          this.inspiration.set(response.state.inspiration === true);

          this.playerSpells.set(response.spells ?? []);
      const character = characterProfileToCharacter(response.character, response.state);
          this.characterStateService.applyServerState(character);

          this.actions.set(response.character.actions ?? []);
          this.characterActions.set(response.state.characterActions ?? null);
          this.activeAction.set(null);
          this.saveStatus.set('saved');
        },

        error: (error) => {
          if (error.status === 409) {
            this.loadCharacter(false);
            this.saveError.set('Le personnage a été modifié ailleurs. Ses données ont été rechargées.');
            return;
          }

          this.saveStatus.set('error');
          this.saveError.set(error.error?.message ?? 'Impossible d’utiliser Festin des héros.');
        },
      });
  }
}

import {
  Component,
  computed,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import {
  ActivatedRoute,
  Router,
  RouterLink,
} from '@angular/router';
import { finalize, forkJoin } from 'rxjs';

import {
  CharacterProfile,
  LevelUpOptions,
  LevelUpPayload,
} from '@core/services/character-api.service';
import {
  CharacterSessionStateApiService,
} from '@core/services/character-session-state-api.service';
import {
  DndReferenceApiService,
  DndReferenceResponse,
} from '@core/services/dnd-reference-api.service';

import {
  CharacterLevelUp,
} from '../campaign-characters/components/character-level-up/character-level-up';

@Component({
  selector: 'app-player-level-up',
  imports: [
    RouterLink,
    CharacterLevelUp,
  ],
  templateUrl: './player-level-up.html',
  styleUrl: './player-level-up.scss',
})
export class PlayerLevelUp implements OnInit {
  private readonly route =
    inject(ActivatedRoute);

  private readonly router =
    inject(Router);

  private readonly characterSessionStateApi =
    inject(CharacterSessionStateApiService);

  private readonly referenceApi =
    inject(DndReferenceApiService);

  private readonly campaignId =
    this.route.snapshot.paramMap.get(
      'campaignId',
    ) ?? '';

  private readonly sessionId =
    this.route.snapshot.paramMap.get(
      'sessionId',
    ) ?? '';

  private readonly accessToken =
    this.route.snapshot.paramMap.get(
      'accessToken',
    ) ?? '';

  protected readonly profile =
    signal<CharacterProfile | null>(null);

  protected readonly options =
    signal<LevelUpOptions | null>(null);

  protected readonly reference =
    signal<DndReferenceResponse | null>(null);

  protected readonly loading =
    signal(true);

  protected readonly submitting =
    signal(false);

  protected readonly error =
    signal<string | null>(null);

  protected readonly abilities = computed(
    () => this.reference()?.abilities ?? [],
  );

  protected readonly feats = computed(
    () => this.reference()?.feats ?? [],
  );

  protected readonly playerPortalRoute = [
    '/campaigns',
    this.campaignId,
    'sessions',
    this.sessionId,
    'player',
    this.accessToken,
  ];

  ngOnInit(): void {
    forkJoin({
      state:
        this.characterSessionStateApi
          .getByAccessToken(
            this.accessToken,
          ),
      options:
        this.characterSessionStateApi
          .getLevelUpOptions(
            this.accessToken,
          ),
      reference:
        this.referenceApi
          .getReference(),
    })
      .pipe(
        finalize(() =>
          this.loading.set(false),
        ),
      )
      .subscribe({
        next: result => {
          this.profile.set(
            result.state.character,
          );
          this.options.set(
            result.options,
          );
          this.reference.set(
            result.reference,
          );
        },
        error: error => {
          this.error.set(
            error?.error?.message
            ?? 'Impossible de charger la montée de niveau.',
          );
        },
      });
  }

  protected submitLevelUp(
    payload: LevelUpPayload,
  ): void {
    if (this.submitting()) {
      return;
    }

    this.submitting.set(true);
    this.error.set(null);

    this.characterSessionStateApi
      .levelUp(
        this.accessToken,
        payload,
      )
      .pipe(
        finalize(() =>
          this.submitting.set(false),
        ),
      )
      .subscribe({
        next: () => {
          this.returnToPortal();
        },
        error: error => {
          this.error.set(
            error?.error?.message
            ?? 'La montée de niveau a échoué.',
          );
        },
      });
  }

  protected returnToPortal(): void {
    void this.router.navigate(
      this.playerPortalRoute,
    );
  }
}

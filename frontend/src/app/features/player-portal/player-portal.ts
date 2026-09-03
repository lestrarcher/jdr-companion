import {
  Component,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core';
import { ActivatedRoute } from '@angular/router';

import { CampaignPlayer } from '@core/models/campaign-player.model';
import { Character } from '@core/models/character.model';
import { RestType } from '@core/models/rest-request.model';
import { CharacterStateService } from '@core/services/character-state.service';
import { LiveSessionService } from '@core/services/live-session.service';
import { RestRequestService } from '@core/services/rest-request.service';
import { STRAHD_CAMPAIGN } from '@data/campaigns/strahd.config';

import {
  CharacterProgressions,
  ProgressionChange,
} from './components/character-progressions/character-progressions';
import { CharacterResources } from './components/character-resources/character-resources';
import { CharacterVitals } from './components/character-vitals/character-vitals';
import { RestControls } from './components/rest-controls/rest-controls';
import { CharacterStoredValues } from './components/character-stored-values/character-stored-values';

@Component({
  selector: 'app-player-portal',
  imports: [
    CharacterProgressions,
    CharacterResources,
    CharacterStoredValues,
    CharacterVitals,
    RestControls,
  ],
  templateUrl: './player-portal.html',
  styleUrl: './player-portal.scss',
})
export class PlayerPortal {
  private readonly route = inject(ActivatedRoute);

  private readonly liveSessionService =
    inject(LiveSessionService);

  private readonly characterStateService =
    inject(CharacterStateService);

  private readonly restRequestService =
    inject(RestRequestService);

  protected readonly campaign = STRAHD_CAMPAIGN;

  protected readonly sessionState =
    this.liveSessionService.state;

  protected readonly characterState =
    this.characterStateService.character;

  protected readonly player: CampaignPlayer | undefined;
  protected readonly character: Character | undefined;

  private readonly sessionId: string;

  protected readonly restFeedback =
    signal<string | null>(null);

  protected readonly sortedResources = computed(() => {
    const character = this.characterState();

    if (!character) {
      return [];
    }

    return character.resources
      .filter((resource) => {
        const condition = resource.unlockCondition;

        if (!condition) {
          return true;
        }

        const progression = character.progressions?.find(
          (currentProgression) =>
            currentProgression.id ===
            condition.progressionId,
        );

        if (!progression) {
          return false;
        }

        return (
          progression.currentValue >=
          condition.minimumValue
        );
      })
      .sort(
        (first, second) =>
          first.displayOrder - second.displayOrder,
      );
  });

  protected readonly storedValueResources = computed(() =>
    this.sortedResources().filter(
      (resource) =>
        resource.storedValuesConfig !== undefined,
    ),
  );

  protected readonly characterRestRequest = computed(() => {
    if (!this.character) {
      return undefined;
    }

    return this.restRequestService
      .requests()
      .find(
        (request) =>
          request.characterId === this.character?.id,
      );
  });

  protected readonly pendingRestRequest = computed(() => {
    const request = this.characterRestRequest();

    return request?.status === 'pending'
      ? request
      : undefined;
  });

  protected readonly closedMessage = computed(() => {
    switch (this.sessionState()?.status) {
      case 'closed':
        return 'Cette session est terminée.';

      case 'draft':
      default:
        return 'La session n’est pas encore ouverte.';
    }
  });

  constructor() {
    const campaignId =
      this.route.snapshot.paramMap.get('campaignId');

    const sessionId =
      this.route.snapshot.paramMap.get('sessionId');

    const accessToken =
      this.route.snapshot.paramMap.get('accessToken');

    if (!campaignId || !sessionId || !accessToken) {
      throw new Error('Lien joueur incomplet.');
    }

    if (campaignId !== this.campaign.id) {
      throw new Error(
        `Campagne inconnue : ${campaignId}`,
      );
    }

    this.sessionId = sessionId;

    this.player = this.campaign.players.find(
      (player) =>
        player.accessToken === accessToken,
    );

    this.character = this.campaign.characters.find(
      (character) =>
        character.id === this.player?.characterId,
    );

    this.liveSessionService.initialize(
      this.campaign,
      this.sessionId,
    );

    this.restRequestService.initialize(
      this.campaign.id,
      this.sessionId,
    );

    if (this.character) {
      this.characterStateService.initialize(
        this.campaign.id,
        this.character,
      );
    }

    effect(() => {
      const request = this.characterRestRequest();

      if (!request || request.status === 'pending') {
        return;
      }

      if (request.status === 'approved') {
        if (request.type === 'short-rest') {
          this.characterStateService.applyShortRest();

          this.showRestFeedback(
            'Repos court accordé par le MJ.',
          );
        } else {
          this.characterStateService.applyLongRest();

          this.showRestFeedback(
            'Repos long accordé par le MJ.',
          );
        }
      } else {
        this.showRestFeedback(
          'Le MJ a refusé le repos.',
        );
      }

      this.restRequestService.clearRequest(request.id);
    });
  }

  protected handleProgressionChange(
    event: ProgressionChange,
  ): void {
    this.characterStateService.adjustProgression(
      event.progressionId,
      event.change,
    );
  }

  protected requestRest(type: RestType): void {
    if (
      !this.character ||
      this.sessionState()?.status !== 'live' ||
      this.pendingRestRequest()
    ) {
      return;
    }

    this.restFeedback.set(null);

    this.restRequestService.createRequest(
      this.campaign.id,
      this.sessionId,
      this.character.id,
      this.character.name,
      type,
    );
  }

  private showRestFeedback(message: string): void {
    this.restFeedback.set(message);

    window.setTimeout(() => {
      this.restFeedback.set(null);
    }, 5000);
  }
}

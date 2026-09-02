import {
  Component,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';

import { CampaignPlayer } from '@core/models/campaign-player.model';
import {
  Character,
  CharacterResource,
} from '@core/models/character.model';
import { RestType } from '@core/models/rest-request.model';
import { CharacterStateService } from '@core/services/character-state.service';
import { LiveSessionService } from '@core/services/live-session.service';
import { RestRequestService } from '@core/services/rest-request.service';
import { STRAHD_CAMPAIGN } from '@data/campaigns/strahd.config';

@Component({
  selector: 'app-player-portal',
  imports: [FormsModule],
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
  protected readonly sessionState = this.liveSessionService.state;
  protected readonly characterState =
    this.characterStateService.character;

  protected readonly player: CampaignPlayer | undefined;
  protected readonly character: Character | undefined;

  private readonly sessionId: string;

  protected hitPointAmount = 0;

  protected readonly restFeedback = signal<string | null>(null);

  protected readonly sortedResources = computed(() =>
    [...(this.characterState()?.resources ?? [])].sort(
      (first, second) =>
        first.displayOrder - second.displayOrder,
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
      throw new Error(`Campagne inconnue : ${campaignId}`);
    }

    this.sessionId = sessionId;

    this.player = this.campaign.players.find(
      (player) => player.accessToken === accessToken,
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
          this.showRestFeedback('Repos court accordé par le MJ.');
        } else {
          this.characterStateService.applyLongRest();
          this.showRestFeedback('Repos long accordé par le MJ.');
        }
      } else {
        this.showRestFeedback('Le MJ a refusé le repos.');
      }

      this.restRequestService.clearRequest(request.id);
    });
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

  protected applyDamage(): void {
    if (this.hitPointAmount <= 0) {
      return;
    }

    this.characterStateService.applyDamage(
      this.hitPointAmount,
    );

    this.hitPointAmount = 0;
  }

  protected heal(): void {
    if (this.hitPointAmount <= 0) {
      return;
    }

    this.characterStateService.heal(
      this.hitPointAmount,
    );

    this.hitPointAmount = 0;
  }

  protected changeTemporaryHitPoints(change: number): void {
    this.characterStateService.adjustTemporaryHitPoints(change);
  }

  protected changeResource(
    resource: CharacterResource,
    change: number,
  ): void {
    if (
      change > 0 &&
      resource.resetPeriod !== 'manual'
    ) {
      return;
    }

    this.characterStateService.adjustResource(
      resource.id,
      change,
    );
  }

  protected changeHitDice(
    hitDicePoolId: string,
    change: number,
  ): void {
    this.characterStateService.adjustHitDice(
      hitDicePoolId,
      change,
    );
  }

  protected updateResourceNotes(
    resourceId: string,
    notes: string,
  ): void {
    this.characterStateService.updateResourceNotes(
      resourceId,
      notes,
    );
  }

  protected restLabel(type: RestType): string {
    return type === 'short-rest'
      ? 'repos court'
      : 'repos long';
  }

  private showRestFeedback(message: string): void {
    this.restFeedback.set(message);

    window.setTimeout(() => {
      this.restFeedback.set(null);
    }, 5000);
  }
}

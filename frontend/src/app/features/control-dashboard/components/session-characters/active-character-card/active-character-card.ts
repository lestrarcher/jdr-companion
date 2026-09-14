import { Component, computed, input, output, signal } from '@angular/core';

import { CharacterApiResponse } from '@core/services/character-api.service';

import {
  MaximumHitPointAdjustment,
  SessionCharacterView,
} from '../session-character.models';

@Component({
  selector: 'app-active-character-card',
  imports: [],
  templateUrl: './active-character-card.html',
  styleUrl: './active-character-card.scss',
})
export class ActiveCharacterCard {
  readonly character = input.required<SessionCharacterView>();

  readonly campaignId = input.required<number>();
  readonly sessionId = input.required<number>();

  readonly changingCharacterId = input<number | null>(null);
  readonly changingLevelUpPermissionId = input<number | null>(null);
  readonly changingMaximumHitPointsId = input<number | null>(null);

  readonly statisticsRequested = output<SessionCharacterView>();
  readonly progressionRequested = output<SessionCharacterView>();
  readonly itemAssignmentRequested = output<SessionCharacterView>();
  readonly levelUpPermissionRequested = output<SessionCharacterView>();
  readonly removalRequested = output<SessionCharacterView>();
  readonly maximumHitPointsRequested =
    output<MaximumHitPointAdjustment>();

  protected readonly maximumHitPointAmount = signal(0);

  protected readonly currentHitPoints = computed(
    () => this.character().sessionState?.state.hitPoints.current ?? null,
  );

  protected readonly temporaryHitPoints = computed(
    () => this.character().sessionState?.state.hitPoints.temporary ?? 0,
  );

  protected readonly maximumHitPoints = computed(() =>
    this.character().sessionState?.state.hitPoints.effectiveMaximum
    ?? this.character().sessionState?.character.hitPoints.maximumValue
    ?? null,
  );

  protected readonly hitPointPercentage = computed(() => {
    const current = this.currentHitPoints();
    const maximum = this.maximumHitPoints();

    if (current === null || maximum === null || maximum <= 0) {
      return 0;
    }

    return Math.min(
      100,
      Math.max(0, (current / maximum) * 100),
    );
  });

  protected readonly hitPointTone = computed<
    'healthy' | 'wounded' | 'critical'
  >(() => {
    const percentage = this.hitPointPercentage();

    if (percentage <= 25) {
      return 'critical';
    }

    if (percentage <= 50) {
      return 'wounded';
    }

    return 'healthy';
  });

  protected characterInitial(name: string): string {
    return name.trim().charAt(0).toUpperCase();
  }

  protected characterSummary(character: CharacterApiResponse): string {
    const classes = character.classLevels.reduce<Record<string, number>>(
      (summary, level) => {
        summary[level.className] =
          (summary[level.className] ?? 0) + 1;

        return summary;
      },
      {},
    );

    return Object.entries(classes)
      .map(([name, level]) => `${name} ${level}`)
      .join(' / ');
  }

  protected playerPortalUrl(): string | null {
    const view = this.character();
    const accessToken = view.sessionState?.accessToken;

    if (!accessToken || !view.participating) {
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

  protected setMaximumHitPointAmount(event: Event): void {
    const value = Number(
      (event.target as HTMLInputElement).value,
    );

    this.maximumHitPointAmount.set(
      Number.isFinite(value) && value > 0
        ? Math.floor(value)
        : 0,
    );
  }

  protected adjustMaximumHitPoints(direction: -1 | 1): void {
    const amount = this.maximumHitPointAmount();

    if (
      amount <= 0
      || this.changingMaximumHitPointsId() !== null
      || !this.character().participating
    ) {
      return;
    }

    this.maximumHitPointsRequested.emit({
      character: this.character(),
      amount,
      direction,
    });
  }

  protected isChangingCharacter(): boolean {
    return (
      this.changingCharacterId()
      === this.character().character.id
    );
  }

  protected isChangingLevelUpPermission(): boolean {
    return (
      this.changingLevelUpPermissionId()
      === this.character().character.id
    );
  }

  protected isChangingMaximumHitPoints(): boolean {
    return (
      this.changingMaximumHitPointsId()
      === this.character().character.id
    );
  }
}

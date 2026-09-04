import {
  Component,
  input,
  signal,
  output,
} from '@angular/core';

import {
  CharacterProgression,
  CharacterResource,
  ProgressionStateIndicator,
} from '@core/models/character.model';

export interface ProgressionChange {
  progressionId: string;
  change: number;
}

export interface ProgressionResourceChange {
  resourceId: string;
  change: number;
}

@Component({
  selector: 'app-character-progressions',
  imports: [],
  templateUrl: './character-progressions.html',
  styleUrl: './character-progressions.scss',
})
export class CharacterProgressions {
  readonly progressions =
    input.required<readonly CharacterProgression[]>();

  readonly resources =
    input<readonly CharacterResource[]>([]);

  readonly progressionChanged =
    output<ProgressionChange>();

  readonly resourceChanged =
    output<ProgressionResourceChange>();

  private readonly bulkValues = signal<
    Record<
      string,
      {
        gain: number;
        spend: number;
      }
    >
  >({});

  protected bulkValue(
    progressionId: string,
    type: 'gain' | 'spend',
  ): number {
    return (
      this.bulkValues()[progressionId]?.[
        type
      ] ?? 0
    );
  }

  protected updateBulkValue(
    progressionId: string,
    type: 'gain' | 'spend',
    event: Event,
  ): void {
    const input =
      event.target as HTMLInputElement;

    const value = Number.isFinite(
      input.valueAsNumber,
    )
      ? Math.max(
          0,
          Math.floor(input.valueAsNumber),
        )
      : 0;

    const previous =
      this.bulkValues()[progressionId] ?? {
        gain: 0,
        spend: 0,
      };

    this.bulkValues.update((values) => ({
      ...values,

      [progressionId]: {
        ...previous,
        [type]: value,
      },
    }));
  }

  protected submitBulkChange(
    progressionId: string,
    type: 'gain' | 'spend',
  ): void {
    const value = this.bulkValue(
      progressionId,
      type,
    );

    if (value <= 0) {
      return;
    }

    this.applyBulkChange(
      progressionId,
      value,
      type === 'gain' ? 1 : -1,
    );

    const previous =
      this.bulkValues()[progressionId] ?? {
        gain: 0,
        spend: 0,
      };

    this.bulkValues.update((values) => ({
      ...values,

      [progressionId]: {
        ...previous,
        [type]: 0,
      },
    }));
  }

  protected changeProgression(
    progressionId: string,
    change: number,
  ): void {
    if (
      !Number.isFinite(change) ||
      change === 0
    ) {
      return;
    }

    this.progressionChanged.emit({
      progressionId,
      change,
    });
  }

  protected applyBulkChange(
    progressionId: string,
    rawValue: number,
    direction: 1 | -1,
  ): void {
    if (
      !Number.isFinite(rawValue) ||
      rawValue <= 0
    ) {
      return;
    }

    const amount = Math.floor(rawValue);

    this.changeProgression(
      progressionId,
      amount * direction,
    );
  }

  protected currentState(
    progression: CharacterProgression,
  ): ProgressionStateIndicator | undefined {
    return progression.states?.find(
      (state) => {
        const reachesMinimum =
          progression.currentValue >=
          state.minimumValue;

        const staysBelowMaximum =
          state.maximumValue === undefined ||
          progression.currentValue <=
            state.maximumValue;

        return (
          reachesMinimum &&
          staysBelowMaximum
        );
      },
    );
  }

  protected linkedResource(
    progression: CharacterProgression,
  ): CharacterResource | undefined {
    const resourceId =
      progression.linkedResource?.resourceId;

    if (!resourceId) {
      return undefined;
    }

    const resource = this.resources().find(
      (candidate) =>
        candidate.id === resourceId,
    );

    if (!resource) {
      return undefined;
    }

    const condition = resource.unlockCondition;

    if (!condition) {
      return resource;
    }

    const requiredProgression =
      this.progressions().find(
        (candidate) =>
          candidate.id ===
          condition.progressionId,
      );

    if (
      !requiredProgression ||
      requiredProgression.currentValue <
        condition.minimumValue
    ) {
      return undefined;
    }

    return resource;
  }

  protected consumeLinkedResource(
    resource: CharacterResource,
  ): void {
    if (resource.currentValue <= 0) {
      return;
    }

    this.resourceChanged.emit({
      resourceId: resource.id,
      change: -1,
    });
  }
}

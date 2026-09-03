import {
  Component,
  effect,
  inject,
  input,
} from '@angular/core';
import { FormsModule } from '@angular/forms';

import { CharacterResource } from '@core/models/character.model';
import { CharacterStateService } from '@core/services/character-state.service';

@Component({
  selector: 'app-character-stored-values',
  imports: [FormsModule],
  templateUrl: './character-stored-values.html',
  styleUrl: './character-stored-values.scss',
})
export class CharacterStoredValues {
  private readonly characterStateService =
    inject(CharacterStateService);

  readonly resources =
    input.required<readonly CharacterResource[]>();

  protected draftValues: Record<
    string,
    Array<number | null>
  > = {};

  constructor() {
    effect(() => {
      for (const resource of this.resources()) {
        const config = resource.storedValuesConfig;

        if (!config) {
          continue;
        }

        /*
         * On prépare les inputs uniquement lorsque les
         * présages n’ont pas encore été générés.
         *
         * Un tableau vide signifie au contraire que tous
         * les présages ont été consommés.
         */
        if (resource.storedValues === undefined) {
          this.draftValues[resource.id] = Array.from(
            {
              length: config.requiredCount,
            },
            () => null,
          );
        }
      }
    });
  }

  protected inputIndexes(
    resource: CharacterResource,
  ): number[] {
    const requiredCount =
      resource.storedValuesConfig?.requiredCount ?? 0;

    return Array.from(
      {
        length: requiredCount,
      },
      (_, index) => index,
    );
  }

  protected updateDraftValue(
    resourceId: string,
    index: number,
    value: number | null,
  ): void {
    const values = [
      ...(this.draftValues[resourceId] ?? []),
    ];

    values[index] =
      value === null || value === undefined
        ? null
        : Number(value);

    this.draftValues[resourceId] = values;
  }

  protected canSave(
    resource: CharacterResource,
  ): boolean {
    const config = resource.storedValuesConfig;

    if (!config) {
      return false;
    }

    const values =
      this.draftValues[resource.id] ?? [];

    return (
      values.length === config.requiredCount &&
      values.every(
        (value) =>
          value !== null &&
          Number.isInteger(value) &&
          value >= config.minimumValue &&
          value <= config.maximumValue,
      )
    );
  }

  protected saveValues(
    resource: CharacterResource,
  ): void {
    if (!this.canSave(resource)) {
      return;
    }

    const values = this.draftValues[resource.id].map(
      (value) => Number(value),
    );

    this.characterStateService.setStoredValues(
      resource.id,
      values,
    );
  }

  protected consumeValue(
    resourceId: string,
    valueIndex: number,
  ): void {
    this.characterStateService.consumeStoredValue(
      resourceId,
      valueIndex,
    );
  }

  protected resetPeriodLabel(
    resetPeriod: CharacterResource['resetPeriod'],
  ): string {
    switch (resetPeriod) {
      case 'short-rest':
        return 'Repos court';

      case 'long-rest':
        return 'Repos long';

      case 'dawn':
        return 'À l’aube';

      case 'manual':
        return 'Manuelle';

      case 'never':
        return 'Aucune';
    }
  }
}

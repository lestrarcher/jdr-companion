import {
  Component,
  inject,
  computed,
  input,
  TemplateRef,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { NgTemplateOutlet } from '@angular/common';

import { CharacterResource } from '@core/models/character.model';
import { CharacterStateService } from '@core/services/character-state.service';

@Component({
  selector: 'app-character-resources',
  imports: [FormsModule, NgTemplateOutlet],
  templateUrl: './character-resources.html',
  styleUrl: './character-resources.scss',
})
export class CharacterResources {
  readonly readonly = input(false);
  readonly tinkeringNotes = input<TemplateRef<unknown> | null>(null);
  readonly hasSupplement = input(false);
  private readonly characterStateService =
    inject(CharacterStateService);

  readonly resources =
    input.required<readonly CharacterResource[]>();

  readonly standardResources = computed(() =>
    this.resources().filter(resource => !resource.storedValuesConfig),
  );

  protected changeResource(
    resource: CharacterResource,
    change: number,
  ): void {
    if (this.readonly()) return;
    const canIncreaseManually =
      resource.resetPeriod === 'manual' ||
      resource.allowManualIncrease === true;

    if (change > 0 && !canIncreaseManually) {
      return;
    }

    this.characterStateService.adjustResource(
      resource.id,
      change,
    );
  }

  protected canIncreaseResource(
    resource: CharacterResource,
  ): boolean {
    return (
      resource.resetPeriod === 'manual' ||
      resource.allowManualIncrease === true
    );
  }

  protected updateResourceNotes(
    resourceId: string,
    notes: string,
  ): void {
    if (this.readonly()) return;
    this.characterStateService.updateResourceNotes(
      resourceId,
      notes,
    );
  }

  protected resetPeriodLabel(
    resetPeriod: CharacterResource['resetPeriod'],
  ): string {
    switch (resetPeriod) {
      case 'short-rest':
        return 'repos court';

      case 'long-rest':
        return 'repos long';

      case 'manual':
        return 'manuelle';

      case 'dawn':
        return 'à l’aube';

      case 'never':
        return 'aucune';
    }
  }
}

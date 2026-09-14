import {
  Component,
  computed,
  input,
  output,
} from '@angular/core';

import { SessionCharacterView } from '../session-character.models';

export interface ProgressionStageView {
  id: number;
  label: string;
  description?: string | null;
  iconUrl?: string | null;
  minimumValue: number;
  maximumValue: number | null;
}

export interface ProgressionAdjustmentRuleView {
  id: number;
  direction: 'gain' | 'loss';
  triggerType: string | null;
  description: string;
  adjustmentLabel: string;
}

export interface ProgressionDetailView {
  slug: string;
  name: string;
  accentColor?: string | null;
  current: number | null;
  stage: ProgressionStageView | null;
  stages: ProgressionStageView[];
  referenceAvailable: boolean;
  gainLabel?: string | null;
  spendLabel?: string | null;
  adjustmentRules: ProgressionAdjustmentRuleView[];
}

@Component({
  selector: 'app-character-progression-modal',
  imports: [],
  templateUrl: './character-progression-modal.html',
  styleUrl: './character-progression-modal.scss',
})
export class CharacterProgressionModal {
  readonly character = input.required<SessionCharacterView>();

  readonly progressions =
    input.required<ProgressionDetailView[]>();

  readonly selectedSlug = input<string | null>(null);
  readonly tab = input<'phases' | 'actions'>('phases');

  readonly loading = input(false);
  readonly error = input<string | null>(null);

  readonly expandedStageIds =
    input.required<ReadonlySet<number>>();

  readonly closed = output<void>();
  readonly progressionSelected = output<string>();
  readonly tabChanged = output<'phases' | 'actions'>();
  readonly stageToggled = output<number>();
  readonly retryRequested = output<void>();

  protected readonly adjustmentDirections =
    ['gain', 'loss'] as const;

  protected readonly selectedProgression = computed(() => {
    const progressions = this.progressions();

    return (
      progressions.find(
        progression =>
          progression.slug === this.selectedSlug(),
      )
      ?? progressions[0]
      ?? null
    );
  });

  protected selectProgression(event: Event): void {
    this.progressionSelected.emit(
      (event.target as HTMLSelectElement).value,
    );
  }

  protected hasAdjustmentRules(
    direction: 'gain' | 'loss',
  ): boolean {
    return (
      this.selectedProgression()?.adjustmentRules.some(
        rule => rule.direction === direction,
      )
      ?? false
    );
  }
}

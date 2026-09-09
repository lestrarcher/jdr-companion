import { Component, input, output, signal } from '@angular/core';
import {
  CharacterProgressionSummary,
} from '@core/services/character-api.service';
import {
  ProgressionReference,
} from '@core/services/dnd-reference-api.service';

@Component({
  selector: 'app-character-progressions',
  standalone: true,
  templateUrl: './character-progressions.html',
  styleUrl: './character-progressions.scss',
})
export class CharacterProgressions {
  readonly assigned = input.required<CharacterProgressionSummary[]>();
  readonly available = input.required<ProgressionReference[]>();
  readonly submitting = input(false);

  readonly progressionAdded = output<number>();
  readonly progressionRemoved = output<number>();
  readonly cancelled = output<void>();

  protected readonly selectedProgressionId = signal<number | null>(null);

  protected add(): void {
    const progressionId = this.selectedProgressionId();

    if (progressionId === null) {
      return;
    }

    this.progressionAdded.emit(progressionId);
  }

  protected remove(progressionId: number): void {
    this.progressionRemoved.emit(progressionId);
  }

  protected selectProgression(value: string): void {
    const progressionId = Number(value);

    this.selectedProgressionId.set(
      Number.isInteger(progressionId) && progressionId > 0
        ? progressionId
        : null,
    );
  }

  protected isAssigned(definitionId: number): boolean {
    return this.assigned().some(
      progression => progression.definitionId === definitionId,
    );
  }
}

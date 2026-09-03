import {
  Component,
  input,
  output,
} from '@angular/core';

import {
  CharacterProgression,
} from '@core/models/character.model';

export interface ProgressionChange {
  progressionId: string;
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

  readonly progressionChanged =
    output<ProgressionChange>();

  protected changeProgression(
    progressionId: string,
    change: number,
  ): void {
    this.progressionChanged.emit({
      progressionId,
      change,
    });
  }
}

import { Component, input } from '@angular/core';
import { CharacterFeatureSummary } from '@core/services/character-api.service';

@Component({
  selector: 'app-character-features',
  templateUrl: './character-features.html',
  styleUrl: './character-features.scss',
})
export class CharacterFeatures {
  readonly features = input.required<CharacterFeatureSummary[]>();

  protected sourceClass(sourceType: string): string {
    return `feature-tag--${sourceType}`;
  }
}

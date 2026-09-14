import { Component, input, output } from '@angular/core';

import {
  CharacterMagicItemInventoryResponse,
  EffectiveAbilityResponse,
} from '@core/services/public-character-magic-item-api.service';

import { SessionCharacterView } from '../session-character.models';

@Component({
  selector: 'app-character-statistics-modal',
  imports: [],
  templateUrl: './character-statistics-modal.html',
  styleUrl: './character-statistics-modal.scss',
})
export class CharacterStatisticsModal {
  readonly character = input.required<SessionCharacterView>();

  readonly inventory =
    input<CharacterMagicItemInventoryResponse | null>(null);

  readonly loading = input(false);
  readonly error = input<string | null>(null);

  readonly closed = output<void>();

  protected abilityLabel(
    ability: EffectiveAbilityResponse['ability'],
  ): string {
    const labels: Record<
      EffectiveAbilityResponse['ability'],
      string
    > = {
      strength: 'Force',
      dexterity: 'Dextérité',
      constitution: 'Constitution',
      intelligence: 'Intelligence',
      wisdom: 'Sagesse',
      charisma: 'Charisme',
    };

    return labels[ability];
  }

  protected formatModifier(modifier: number): string {
    return modifier >= 0
      ? `+${modifier}`
      : `${modifier}`;
  }
}

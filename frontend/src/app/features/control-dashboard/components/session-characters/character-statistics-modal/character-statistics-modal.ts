import { Component, input, output, signal } from '@angular/core';

import {
  CharacterMagicItemInventoryResponse,
  CharacterMagicItemResponse,
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

  readonly rechargingDawn = input(false);
  readonly dawnRequested = output<void>();
  readonly closed = output<void>();
  readonly removing = input(false);
  readonly itemRemoved = output<{item: CharacterMagicItemResponse; quantity: number}>();
  protected readonly pendingRemoval = signal<CharacterMagicItemResponse | null>(null);
  protected readonly removalQuantity = signal(1);
  protected requestRemoval(item: CharacterMagicItemResponse): void { this.pendingRemoval.set(item); this.removalQuantity.set(1); }
  protected setRemovalQuantity(value: number): void {
    const item = this.pendingRemoval();
    if (item && Number.isInteger(value)) this.removalQuantity.set(Math.max(1, Math.min(item.quantity, value)));
  }
  protected confirmRemoval(): void {
    const item = this.pendingRemoval();
    if (item && !this.removing()) { this.itemRemoved.emit({item, quantity: this.removalQuantity()}); this.pendingRemoval.set(null); }
  }

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

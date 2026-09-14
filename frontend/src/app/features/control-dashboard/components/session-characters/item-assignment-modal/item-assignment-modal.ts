import { Component, computed, input, output } from '@angular/core';

import {
  MagicItemResponse,
} from '@core/services/public-character-magic-item-api.service';

import { SessionCharacterView } from '../session-character.models';

@Component({
  selector: 'app-item-assignment-modal',
  imports: [],
  templateUrl: './item-assignment-modal.html',
  styleUrl: './item-assignment-modal.scss',
})
export class ItemAssignmentModal {
  readonly character = input.required<SessionCharacterView>();

  readonly catalog = input.required<MagicItemResponse[]>();
  readonly loading = input(false);
  readonly selectedMagicItemId = input<number | null>(null);
  readonly assigning = input(false);
  readonly feedback = input<string | null>(null);
  readonly error = input<string | null>(null);

  readonly closed = output<void>();
  readonly magicItemSelected = output<number | null>();
  readonly assignmentRequested = output<void>();

  protected readonly selectedMagicItem = computed(() => {
    const selectedId = this.selectedMagicItemId();

    if (selectedId === null) {
      return null;
    }

    return (
      this.catalog().find(item => item.id === selectedId)
      ?? null
    );
  });

  protected selectMagicItem(event: Event): void {
    const value =
      (event.target as HTMLSelectElement).value;

    this.magicItemSelected.emit(
      value ? Number(value) : null,
    );
  }

  protected close(): void {
    if (this.assigning()) {
      return;
    }

    this.closed.emit();
  }
}

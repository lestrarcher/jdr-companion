import { Component, computed, input, output, signal } from '@angular/core';

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

  readonly search = signal('');
  readonly quantity = signal(1);
  readonly catalogSearch = output<string>();
  readonly filteredCatalog = computed(() => this.catalog().filter(item => item.name.toLocaleLowerCase().includes(this.search().trim().toLocaleLowerCase())).slice(0, 80));

  readonly closed = output<void>();
  readonly magicItemSelected = output<number | null>();
  readonly assignmentRequested = output<number>();

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

  protected updateSearch(value: string): void { this.search.set(value); this.catalogSearch.emit(value); }
  protected selectItem(id: number): void { this.magicItemSelected.emit(id); }
  protected changeQuantity(value: number): void { if (Number.isInteger(value)) this.quantity.set(Math.max(1, Math.min(9999, value))); }
  protected requestAssignment(): void { this.assignmentRequested.emit(this.quantity()); }

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

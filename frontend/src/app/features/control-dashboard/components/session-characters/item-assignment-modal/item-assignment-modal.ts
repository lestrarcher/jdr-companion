import { Component, computed, inject, input, output, signal } from '@angular/core';
import { MagicItemApiService } from '@core/services/magic-item-api.service';

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

  private readonly magicItems = inject(MagicItemApiService);
  readonly scrollQuery = signal('');
  readonly scrollSpells = signal<{id: number; name: string; level: number}[]>([]);
  readonly selectedScrollSpellId = signal<number | null>(null);
  private scrollRequest = 0;
  readonly bookQuery = signal('');
  readonly bookSpells = signal<{id: number; name: string; level: number}[]>([]);
  readonly selectedBookSpells = signal<{id: number; name: string; level: number}[]>([]);
  readonly bookNotes = signal('');
  private bookRequest = 0;
  readonly search = signal('');
  readonly quantity = signal(1);
  readonly catalogSearch = output<string>();
  readonly filteredCatalog = computed(() => this.catalog().filter(item => item.name.toLocaleLowerCase().includes(this.search().trim().toLocaleLowerCase())).slice(0, 80));

  readonly closed = output<void>();
  readonly magicItemSelected = output<number | null>();
  readonly assignmentRequested = output<{quantity: number; inscribedSpellId?: number; spellIds?: number[]; notes?: string}>();

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
  protected selectItem(id: number): void {
    this.selectedScrollSpellId.set(null);
    this.selectedBookSpells.set([]);
    this.bookSpells.set([]);
    this.bookNotes.set('');
    this.bookQuery.set('');
    ++this.bookRequest;
    this.scrollSpells.set([]);
    this.scrollQuery.set('');
    this.magicItemSelected.emit(id);
    const item = this.catalog().find(candidate => candidate.id === id);
    if (item?.isSpellScroll) this.searchScrollSpells('');
    if (item?.category === 'spellbook') this.searchBookSpells('');
  }
  protected searchScrollSpells(query: string): void {
    this.scrollQuery.set(query);
    this.selectedScrollSpellId.set(null);
    const request = ++this.scrollRequest;
    this.magicItems.searchScrollSpells(this.character().character.id, query).subscribe({
      next: response => { if (request === this.scrollRequest) this.scrollSpells.set(response.spells); },
      error: () => { if (request === this.scrollRequest) this.scrollSpells.set([]); },
    });
  }
  protected searchBookSpells(query: string): void {
    this.bookQuery.set(query);
    const request = ++this.bookRequest;
    this.magicItems.searchScrollSpells(this.character().character.id, query).subscribe({
      next: response => { if (request === this.bookRequest) this.bookSpells.set(response.spells.filter(spell => spell.level > 0)); },
      error: () => { if (request === this.bookRequest) this.bookSpells.set([]); },
    });
  }
  protected toggleBookSpell(spell: {id: number; name: string; level: number}): void {
    this.selectedBookSpells.update(current => current.some(entry => entry.id === spell.id)
      ? current.filter(entry => entry.id !== spell.id)
      : current.length < 100 ? [...current, spell] : current);
  }
  protected bookSpellSelected(id: number): boolean {
    return this.selectedBookSpells().some(spell => spell.id === id);
  }
  protected changeQuantity(value: number): void { if (Number.isInteger(value)) this.quantity.set(Math.max(1, Math.min(9999, value))); }
  protected requestAssignment(): void {
    const item = this.selectedMagicItem();
    if (item?.isSpellScroll && this.selectedScrollSpellId() === null) return;
    if (item?.category === 'spellbook') {
      this.assignmentRequested.emit({quantity: 1, spellIds: this.selectedBookSpells().map(spell => spell.id), notes: this.bookNotes()});
      return;
    }
    this.assignmentRequested.emit({ quantity: this.quantity(), ...(item?.isSpellScroll ? {inscribedSpellId: this.selectedScrollSpellId()!} : {}) });
  }

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

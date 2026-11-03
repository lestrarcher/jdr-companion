import { Component, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

interface Item {
  id: number; name: string; englishName: string | null; description: string | null;
  category: string; rarity: string | null; priceCopper: number | null; weightGrams: number | null;
}
@Component({
  selector: 'app-item-reference', standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './item-reference.html', styleUrl: './item-reference.scss',
})
export class ItemReference {
  private readonly http = inject(HttpClient);
  readonly items = signal<Item[]>([]);
  readonly loading = signal(true);
  readonly error = signal('');
  readonly query = signal('');
  readonly rarity = signal('all');
  readonly selected = signal<Item | null>(null);
  readonly filtered = computed(() => {
    const q = this.query().trim().toLocaleLowerCase();
    return this.items().filter(item =>
      (this.rarity() === 'all' || (this.rarity() === 'none' ? item.rarity === null : item.rarity === this.rarity())) &&
      (!q || `${item.name} ${item.englishName ?? ''}`.toLocaleLowerCase().includes(q)));
  });
  constructor() {
    this.http.get<{items: Item[]}>('/api/dnd/items').subscribe({
      next: res => { this.items.set(res.items); this.loading.set(false); },
      error: () => { this.error.set('Impossible de charger le référentiel.'); this.loading.set(false); },
    });
  }
  price(item: Item): string {
    if (item.priceCopper === null) return '—';
    const c = item.priceCopper;
    return c >= 100 && c % 100 === 0 ? `${c / 100} po` : c >= 10 && c % 10 === 0 ? `${c / 10} pa` : `${c} pc`;
  }
  weight(item: Item): string {
    if (item.weightGrams === null) return '—';
    return item.weightGrams >= 1000 ? `${(item.weightGrams / 1000).toLocaleString('fr-FR')} kg` : `${item.weightGrams} g`;
  }
}

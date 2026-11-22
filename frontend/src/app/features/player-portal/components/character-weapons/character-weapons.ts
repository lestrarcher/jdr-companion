import { ChangeDetectionStrategy, Component, computed, ElementRef, inject, input, output, signal, viewChild } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { CharacterWeapon } from '@core/models/character-weapon.model';
import {
  CharacterMagicItemResponse,
  PublicCharacterMagicItemApiService,
} from '@core/services/public-character-magic-item-api.service';

@Component({selector:'app-character-weapons', standalone:true, templateUrl:'./character-weapons.html', styleUrl:'./character-weapons.scss', changeDetection:ChangeDetectionStrategy.OnPush})
export class CharacterWeapons {
  readonly weapons = input.required<CharacterWeapon[]>();
  readonly accessToken = input('');
  readonly naturalForm = input(false);
  readonly readonly = input(false);
  readonly refreshRevision = input(0);
  readonly chargesChanged = output<void>();

  private readonly api = inject(PublicCharacterMagicItemApiService);
  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('weaponDialog');
  protected readonly selectedWeapon = computed(() => this.weapons().find(weapon => weapon.id === this.expandedId()) ?? null);
  protected readonly expandedId = signal<number | string | null>(null);
  protected readonly inventory = signal<CharacterMagicItemResponse[]>([]);
  protected readonly loading = signal(false);
  protected readonly updatingId = signal<number | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly itemsById = computed(() => new Map<number | string, CharacterMagicItemResponse>(this.inventory().map(item => [item.id, item])));

  protected visibleNotes(notes: string[]): string[] {
    return notes
      .map(note => note.replace(/\s*\(?meilleur mod(?:ificateur)?\s+FOR\s*\/\s*DEX\)?/gi, '').trim())
      .filter(Boolean);
  }

  protected toggleDetails(id: number | string): void {
    this.expandedId.set(id);
    this.error.set(null);
    const dialog = this.dialog().nativeElement;
    if (!dialog.open) dialog.showModal();
    if (!this.naturalForm() && typeof id === 'number') this.loadInventory();
  }

  protected closeDetails(): void {
    this.dialog().nativeElement.close();
    this.expandedId.set(null);
  }

  protected cancelDetails(event: Event): void {
    event.preventDefault();
    this.closeDetails();
  }

  protected onDialogClosed(): void { this.expandedId.set(null); }

  protected attackLabel(value: number | null): string {
    return value === null ? '—' : `${value >= 0 ? '+' : ''}${value}`;
  }

  protected rarityLabel(rarity: string | null): string {
    return ({ common: 'Commun', uncommon: 'Peu commun', rare: 'Rare',
      'very-rare': 'Très rare', legendary: 'Légendaire', artifact: 'Artéfact' } as Record<string, string>)[rarity ?? ''] ?? 'Objet magique';
  }

  protected loadInventory(): void {
    if (!this.accessToken() || this.loading()) return;
    this.loading.set(true);
    this.api.list(this.accessToken()).subscribe({
      next: response => { this.inventory.set(response.ownedItems); this.loading.set(false); },
      error: () => { this.error.set('Impossible de charger les détails de cette arme.'); this.loading.set(false); },
    });
  }

  protected spendCharge(item: CharacterMagicItemResponse): void {
    if (this.readonly() || this.updatingId() !== null || item.currentCharges <= 0 || item.magicItem.maximumCharges === null) return;
    this.updatingId.set(item.id);
    this.error.set(null);
    this.api.changeCharges(this.accessToken(), item.id, -1).subscribe({
      next: response => {
        this.inventory.set(response.ownedItems);
        this.updatingId.set(null);
        this.chargesChanged.emit();
      },
      error: (err: HttpErrorResponse) => {
        this.error.set(typeof err.error?.error === 'string' ? err.error.error : 'Impossible de dépenser cette charge.');
        this.updatingId.set(null);
        this.loadInventory();
      },
    });
  }
}

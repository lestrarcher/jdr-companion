import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, input, OnInit, output, signal } from '@angular/core';
import { CharacterProfile } from '@core/services/character-api.service';
import { MagicItemApiService } from '@core/services/magic-item-api.service';
import { CharacterMagicItemInventoryResponse, MagicItemResponse } from '@core/services/public-character-magic-item-api.service';

@Component({
  selector: 'app-character-equipment',
  standalone: true,
  templateUrl: './character-equipment.html',
  styleUrl: './character-equipment.scss',
})
export class CharacterEquipment implements OnInit {
  readonly character = input.required<CharacterProfile>();
  readonly campaignId = input.required<number>();
  readonly equipmentChanged = output<void>();
  private readonly api = inject(MagicItemApiService);
  protected readonly inventory = signal<CharacterMagicItemInventoryResponse | null>(null);
  protected readonly catalogue = signal<MagicItemResponse[]>([]);
  protected readonly search = signal('');
  protected readonly selectedId = signal<number | null>(null);
  protected readonly quantity = signal(1);
  protected readonly busy = signal(false);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly notice = signal<string | null>(null);
  protected readonly matches = computed(() => {
    const term = this.search().trim().toLocaleLowerCase('fr');
    return this.catalogue().filter(item => item.name.toLocaleLowerCase('fr').includes(term)).slice(0, 40);
  });

  ngOnInit(): void { this.refresh(); }

  protected equip(ownedId: number, equipped: boolean): void {
    if (this.busy()) return;
    this.busy.set(true);
    this.error.set(null);
    this.api.equipCharacterItem(this.character().id, ownedId, equipped).subscribe({
      next: () => { this.busy.set(false); this.refresh(); this.equipmentChanged.emit(); },
      error: error => { this.busy.set(false); this.fail(error); },
    });
  }

  protected refresh(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.listCharacterItems(this.character().id).subscribe({
      next: inventory => { this.inventory.set(inventory); this.loading.set(false); },
      error: error => { this.fail(error); this.loading.set(false); },
    });
    this.api.listCatalog(this.campaignId()).subscribe({
      next: items => this.catalogue.set(items),
      error: error => this.fail(error),
    });
  }

  protected assign(): void {
    const magicItemId = this.selectedId();
    if (magicItemId === null || !Number.isInteger(this.quantity()) || this.quantity() < 1 || this.quantity() > 9999 || this.busy()) return;
    this.busy.set(true);
    this.error.set(null);
    this.notice.set(null);
    this.api.assignToCharacter(this.character().id, { magicItemId, quantity: this.quantity() }).subscribe({
      next: () => {
        this.busy.set(false);
        this.notice.set('Objet attribué au personnage.');
        this.api.listCharacterItems(this.character().id).subscribe({
          next: inventory => this.inventory.set(inventory),
          error: error => this.fail(error),
        });
      },
      error: error => { this.busy.set(false); this.fail(error); },
    });
  }

  protected remove(ownedId: number): void {
    if (this.busy() || !confirm('Retirer cet objet de l’inventaire ?')) return;
    this.busy.set(true);
    this.error.set(null);
    this.api.removeFromCharacter(this.character().id, ownedId).subscribe({
      next: () => {
        this.busy.set(false);
        this.equipmentChanged.emit();
        this.api.listCharacterItems(this.character().id).subscribe({
          next: inventory => this.inventory.set(inventory),
          error: error => this.fail(error),
        });
      },
      error: error => { this.busy.set(false); this.fail(error); },
    });
  }

  private fail(error: unknown): void {
    this.error.set(error instanceof HttpErrorResponse && typeof error.error?.message === 'string'
      ? error.error.message : 'Impossible de charger ou modifier l’inventaire.');
  }
}

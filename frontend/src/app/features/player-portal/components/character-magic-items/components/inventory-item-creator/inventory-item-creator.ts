import { ChangeDetectionStrategy, Component, computed, inject, input, output, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { CharacterMagicItemInventoryResponse, MagicItemRarity, MagicItemResponse, PublicCharacterMagicItemApiService } from '@core/services/public-character-magic-item-api.service';

@Component({
  selector: 'app-inventory-item-creator', standalone: true, imports: [],
  templateUrl: './inventory-item-creator.html', styleUrl: './inventory-item-creator.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class InventoryItemCreator {
  readonly readonly = input(false);
  readonly accessToken = input.required<string>();
  readonly inventoryChanged = output<CharacterMagicItemInventoryResponse>();
  private readonly magicItemApi = inject(PublicCharacterMagicItemApiService);
  protected readonly errorMessage = signal<string | null>(null);
  protected readonly customOpen = signal(false);
  protected readonly customName = signal('');
  protected readonly customDescription = signal('');
  protected readonly customQuantity = signal(1);
  protected readonly customBusy = signal(false);
  protected readonly customError = signal<string | null>(null);
  protected readonly validCustomItem = computed(() => this.customName().trim().length > 0
    && this.customName().trim().length <= 200 && this.customDescription().length <= 10000
    && Number.isInteger(this.customQuantity()) && this.customQuantity() >= 1 && this.customQuantity() <= 9999);

  protected openCustom(): void {
    if (this.readonly()) return;
    this.customName.set('');
    this.customDescription.set('');
    this.customQuantity.set(1);
    this.customError.set(null);
    this.customOpen.set(true);
  }

  protected createCustom(): void {
    if (this.readonly() || this.customBusy() || !this.validCustomItem()) return;
    this.customBusy.set(true);
    this.customError.set(null);
    this.successMessage.set(null);
    this.magicItemApi.createCustom(this.accessToken(), this.customName().trim(), this.customDescription(), this.customQuantity()).subscribe({
      next: inventory => {
        this.inventoryChanged.emit(inventory);
        this.customBusy.set(false);
        this.customOpen.set(false);
        this.successMessage.set('Objet personnel ajouté à l’inventaire.');
      },
      error: (error: HttpErrorResponse) => {
        this.customBusy.set(false);
        this.customError.set(typeof error.error?.message === 'string' ? error.error.message : 'Impossible de créer cet objet.');
      },
    });
  }

  protected readonly catalogueQuery = signal('');
  protected readonly catalogueItems = signal<MagicItemResponse[]>([]);
  protected readonly cataloguePage = signal(1);
  protected readonly catalogueTotal = signal(0);
  protected readonly catalogueLoading = signal(false);
  private catalogueRequestId = 0;
  protected readonly acquiring = signal(false);
  protected readonly quantity = signal(1);
  protected readonly scrollSpellQuery = signal('');
  protected readonly scrollSpells = signal<{id: number; name: string; level: number}[]>([]);
  protected readonly selectedScrollSpellId = signal<number | null>(null);
  protected readonly scrollSpellsLoading = signal(false);
  private scrollRequestId = 0;
  protected readonly catalogueOpen = signal(false);
  protected readonly selectedCatalogueItem = signal<MagicItemResponse | null>(null);
  protected readonly successMessage = signal<string | null>(null);

  protected openCatalogue(): void {
    if (this.readonly()) return;
    this.catalogueOpen.set(true);
    this.selectedCatalogueItem.set(null);
    this.quantity.set(1);
    this.loadCatalogue(1);
  }

  protected closeCatalogue(): void {
    if (this.acquiring()) return;
    this.catalogueOpen.set(false);
    this.selectedCatalogueItem.set(null);
  }

  protected selectCatalogueItem(item: MagicItemResponse): void {
    this.selectedCatalogueItem.set(item);
    this.quantity.set(1);
    this.selectedScrollSpellId.set(null);
    this.scrollSpellQuery.set('');
    this.scrollSpells.set([]);
    if (item.isSpellScroll) this.searchScrollSpells('');
  }

  protected searchScrollSpells(query: string): void {
    this.scrollSpellQuery.set(query);
    this.selectedScrollSpellId.set(null);
    const requestId = ++this.scrollRequestId;
    this.scrollSpellsLoading.set(true);
    this.magicItemApi.searchScrollSpells(this.accessToken(), query).subscribe({
      next: response => {
        if (requestId !== this.scrollRequestId) return;
        this.scrollSpells.set(response.spells);
        this.scrollSpellsLoading.set(false);
      },
      error: (error: HttpErrorResponse) => {
        if (requestId !== this.scrollRequestId) return;
        this.scrollSpellsLoading.set(false);
        this.showError(error);
      },
    });
  }

  protected validQuantity(): boolean {
    return Number.isInteger(this.quantity()) && this.quantity() >= 1 && this.quantity() <= 9999;
  }

  protected adjustQuantity(change: number): void {
    this.quantity.set(Math.max(1, Math.min(9999, this.quantity() + change)));
  }

  protected rarityClass(rarity: MagicItemRarity | null): string {
    return rarity === null ? 'mundane' : rarity;
  }

  protected searchCatalogue(value: string): void {
    this.catalogueQuery.set(value);
    this.selectedCatalogueItem.set(null);
    this.loadCatalogue(1);
  }

  protected loadCatalogue(page: number): void {
    if (this.readonly()) return;
    const requestId = ++this.catalogueRequestId;
    this.catalogueLoading.set(true);
    this.magicItemApi.catalogue(this.accessToken(), this.catalogueQuery(), page).subscribe({
      next: response => {
        if (requestId !== this.catalogueRequestId) return;
        this.catalogueItems.set(response.items);
        this.catalogueTotal.set(response.total);
        this.cataloguePage.set(response.page);
        this.catalogueLoading.set(false);
      },
      error: (error: HttpErrorResponse) => {
        if (requestId !== this.catalogueRequestId) return;
        this.catalogueLoading.set(false);
        this.showError(error);
      },
    });
  }

  protected acquireItem(item: MagicItemResponse): void {
    if (this.readonly() || this.acquiring() || this.selectedCatalogueItem()?.id !== item.id) return;
    const quantity = this.quantity();
    if (!Number.isInteger(quantity) || quantity < 1 || quantity > 9999) return;
    if (item.isSpellScroll && this.selectedScrollSpellId() == null) return;
    this.acquiring.set(true);
    this.errorMessage.set(null);
    this.successMessage.set(null);
    this.magicItemApi.acquire(this.accessToken(), item.id, quantity, item.isSpellScroll ? this.selectedScrollSpellId()! : undefined).subscribe({
      next: inventory => {
        this.inventoryChanged.emit(inventory);
        this.acquiring.set(false);
        this.successMessage.set(`${quantity} × ${item.name} ajouté à l’inventaire.`);
        this.closeCatalogue();
      },
      error: (error: HttpErrorResponse) => {
        this.acquiring.set(false);
        this.showError(error);
      },
    });
  }

  protected rarityLabel(rarity: MagicItemRarity | null): string {
    const labels: Record<MagicItemRarity,string> = { common:'Commun', uncommon:'Peu commun', rare:'Rare', 'very-rare':'Très rare', legendary:'Légendaire', artifact:'Artefact' };
    return rarity === null ? 'Sans rareté' : labels[rarity];
  }
  protected formatWeight(grams: number): string { return grams >= 1000 ? `${(grams / 1000).toLocaleString('fr-FR', {maximumFractionDigits:2})} kg` : `${grams} g`; }
  private showError(error: HttpErrorResponse): void { this.errorMessage.set(typeof error.error?.message === 'string' ? error.error.message : 'Impossible de mettre à jour les objets magiques.'); }
}

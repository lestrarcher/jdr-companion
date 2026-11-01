import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  OnInit,
  OnChanges,
  SimpleChanges,
  signal,
} from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';

import {
  CharacterMagicItemInventoryResponse,
  CharacterMagicItemResponse,
  MagicItemRarity,
  MagicItemResponse,
  PublicCharacterMagicItemApiService,
} from '@core/services/public-character-magic-item-api.service';

@Component({
  selector: 'app-character-magic-items',
  standalone: true,
  imports: [],
  templateUrl: './character-magic-items.html',
  styleUrl: './character-magic-items.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CharacterMagicItems implements OnInit, OnChanges {
  readonly readonly = input(false);
  readonly accessToken = input.required<string>();
  readonly refreshRevision = input(0);

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['refreshRevision'] && !changes['refreshRevision'].firstChange) {
      this.loadInventory();
    }
  }

  private readonly magicItemApi =
    inject(PublicCharacterMagicItemApiService);

  protected readonly inventory =
    signal<CharacterMagicItemInventoryResponse | null>(null);

  protected readonly loading = signal(true);
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

  protected readonly expandedItemId = signal<number | null>(null);
  protected readonly pendingAction = signal<{ item: CharacterMagicItemResponse; kind: 'consume' | 'discard' } | null>(null);
  protected readonly discardQuantity = signal(1);

  protected readonly sortedItems = computed(() => [...this.ownedItems()].sort((a, b) =>
    (a.inscribedSpellName ?? a.magicItem.name).localeCompare(b.inscribedSpellName ?? b.magicItem.name, 'fr', { sensitivity: 'base' }) || a.id - b.id));
  protected readonly totalWeightGrams = computed(() => this.ownedItems().reduce((sum, item) =>
    sum + (item.magicItem.weightGrams ?? 0) * item.quantity, 0));
  protected readonly unknownWeight = computed(() => this.ownedItems().some(item => item.magicItem.weightGrams == null));
  protected formatWeight(grams: number): string {
    return grams >= 1000 ? `${(grams / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 2 })} kg` : `${grams} g`;
  }
  protected formatPrice(copper: number | null | undefined): string {
    if (copper == null) return 'Non renseigné';
    if (copper === 0) return '0 pc';
    return copper >= 100 ? `${(copper / 100).toLocaleString('fr-FR', { maximumFractionDigits: 2 })} po` : `${copper} pc`;
  }
  protected toggleDetails(id: number): void {
    this.expandedItemId.set(this.expandedItemId() === id ? null : id);
  }
  protected askConsume(item: CharacterMagicItemResponse): void {
    if (this.readonly() || this.isUpdating(item)) return;
    this.pendingAction.set({ item, kind: 'consume' });
  }
  protected askDiscard(item: CharacterMagicItemResponse): void {
    if (this.readonly() || this.isUpdating(item)) return;
    this.discardQuantity.set(1);
    this.pendingAction.set({ item, kind: 'discard' });
  }
  protected cancelAction(): void { this.pendingAction.set(null); }
  protected confirmAction(): void {
    const action = this.pendingAction();
    if (!action || this.readonly() || this.isUpdating(action.item)) return;
    if (action.kind === 'consume') {
      this.pendingAction.set(null);
      this.consumeItem(action.item);
      return;
    }
    const quantity = this.discardQuantity();
    if (!Number.isInteger(quantity) || quantity < 1 || quantity > action.item.quantity) return;
    this.pendingAction.set(null);
    this.startUpdate(action.item.id);
    this.magicItemApi.discard(this.accessToken(), action.item.id, quantity).subscribe({
      next: response => {
        this.inventory.set(response);
        this.successMessage.set(`${quantity} × ${action.item.magicItem.name} retiré de l’inventaire.`);
        this.finishUpdate();
      },
      error: (error: HttpErrorResponse) => this.handleError(error),
    });
  }

  protected readonly updatingItemId = signal<number | null>(null);
  protected readonly errorMessage = signal<string | null>(null);

  protected readonly ownedItems = computed(
    () => this.inventory()?.ownedItems ?? [],
  );

  protected readonly attunedCount = computed(
    () => this.inventory()?.attunedCount ?? 0,
  );

  protected readonly attunementLimit = computed(
    () => this.inventory()?.attunementLimit ?? 3,
  );

  protected readonly hasItems = computed(
    () => this.ownedItems().length > 0,
  );

  ngOnInit(): void {
    this.loadInventory();
  }

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
        this.handleError(error);
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
        this.handleError(error);
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
        this.inventory.set(inventory);
        this.acquiring.set(false);
        this.successMessage.set(`${quantity} × ${item.name} ajouté à l’inventaire.`);
        this.closeCatalogue();
      },
      error: (error: HttpErrorResponse) => {
        this.acquiring.set(false);
        this.handleError(error);
      },
    });
  }

  protected canEquip(ownedItem: CharacterMagicItemResponse): boolean {
    return ownedItem.magicItem.equippable === true;
  }

  protected canConsume(ownedItem: CharacterMagicItemResponse): boolean {
    return ownedItem.magicItem.category === 'potion';
  }

  protected consumeItem(ownedItem: CharacterMagicItemResponse): void {
    if (this.readonly() || this.isUpdating(ownedItem)) return;
    this.startUpdate(ownedItem.id);
    this.magicItemApi.consume(this.accessToken(), ownedItem.id).subscribe({
      next: response => {
        this.inventory.set(response);
        this.successMessage.set(`1 × ${ownedItem.magicItem.name} consommé.`);
        this.finishUpdate();
      },
      error: (error: HttpErrorResponse) => this.handleError(error),
    });
  }

  protected toggleEquipped(
    ownedItem: CharacterMagicItemResponse,
  ): void {
    if (this.readonly() || !this.canEquip(ownedItem)) return;
    if (this.isUpdating(ownedItem)) {
      return;
    }

    this.startUpdate(ownedItem.id);

    this.magicItemApi
      .equip(
        this.accessToken(),
        ownedItem.id,
        !ownedItem.equipped,
      )
      .subscribe({
        next: (response) => {
          this.inventory.set(response);
          this.finishUpdate();
        },
        error: (error: HttpErrorResponse) => {
          this.handleError(error);
        },
      });
  }

  protected toggleAttuned(
    ownedItem: CharacterMagicItemResponse,
  ): void {
    if (this.readonly()) return;
    if (
      this.isUpdating(ownedItem)
      || !ownedItem.magicItem.requiresAttunement
      || this.isAttunementDisabled(ownedItem)
    ) {
      return;
    }

    this.startUpdate(ownedItem.id);

    this.magicItemApi
      .attune(
        this.accessToken(),
        ownedItem.id,
        !ownedItem.attuned,
      )
      .subscribe({
        next: (response) => {
          this.inventory.set(response);
          this.finishUpdate();
        },
        error: (error: HttpErrorResponse) => {
          this.handleError(error);
        },
      });
  }

  protected spendCharge(
    ownedItem: CharacterMagicItemResponse,
  ): void {
    if (this.readonly()) return;
    if (
      this.isUpdating(ownedItem)
      || ownedItem.currentCharges <= 0
    ) {
      return;
    }

    this.changeCharges(ownedItem, -1);
  }

  protected restoreCharge(
    ownedItem: CharacterMagicItemResponse,
  ): void {
    if (this.readonly()) return;
    const maximumCharges =
      ownedItem.magicItem.maximumCharges;

    if (
      this.isUpdating(ownedItem)
      || maximumCharges === null
      || ownedItem.currentCharges >= maximumCharges
      || ownedItem.magicItem.rechargeType !== 'manual'
    ) {
      return;
    }

    this.changeCharges(ownedItem, 1);
  }

  protected hasCharges(
    ownedItem: CharacterMagicItemResponse,
  ): boolean {
    return ownedItem.magicItem.maximumCharges !== null;
  }

  protected canRestoreManually(
    ownedItem: CharacterMagicItemResponse,
  ): boolean {
    return ownedItem.magicItem.rechargeType === 'manual';
  }

  protected isUpdating(
    ownedItem: CharacterMagicItemResponse,
  ): boolean {
    return this.updatingItemId() === ownedItem.id;
  }

  protected isAttunementDisabled(
    ownedItem: CharacterMagicItemResponse,
  ): boolean {
    return (
      !ownedItem.attuned
      && this.attunedCount() >= this.attunementLimit()
    );
  }

  protected rarityLabel(
    rarity: MagicItemRarity | null,
  ): string {
    const labels: Record<MagicItemRarity, string> = {
      common: 'Commun',
      uncommon: 'Peu commun',
      rare: 'Rare',
      'very-rare': 'Très rare',
      legendary: 'Légendaire',
      artifact: 'Artefact',
    };

    return rarity === null ? 'Sans rareté' : labels[rarity];
  }

  private loadInventory(): void {
    this.loading.set(true);
    this.errorMessage.set(null);

    this.magicItemApi
      .list(this.accessToken())
      .subscribe({
        next: (inventory) => {
          this.inventory.set(inventory);
          this.loading.set(false);
        },
        error: (error: HttpErrorResponse) => {
          this.loading.set(false);
          this.handleError(error);
        },
      });
  }

  private changeCharges(
    ownedItem: CharacterMagicItemResponse,
    chargeChange: number,
  ): void {
    if (this.readonly()) return;
    this.startUpdate(ownedItem.id);

    this.magicItemApi
      .changeCharges(
        this.accessToken(),
        ownedItem.id,
        chargeChange,
      )
      .subscribe({
        next: (response) => {
          this.inventory.set(response);
          this.finishUpdate();
        },
        error: (error: HttpErrorResponse) => {
          this.handleError(error);
        },
      });
  }

  private startUpdate(ownedItemId: number): void {
    this.errorMessage.set(null);
    this.updatingItemId.set(ownedItemId);
  }

  private finishUpdate(): void {
    this.updatingItemId.set(null);
  }

  private handleError(error: HttpErrorResponse): void {
    const message =
      typeof error.error?.message === 'string'
        ? error.error.message
        : 'Impossible de mettre à jour les objets magiques.';

    this.errorMessage.set(message);
    this.finishUpdate();
  }
}

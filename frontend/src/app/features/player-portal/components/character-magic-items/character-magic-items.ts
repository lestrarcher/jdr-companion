import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  output,
  OnInit,
  OnChanges,
  SimpleChanges,
  signal,
} from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { SpellbookCopy } from './components/spellbook-copy/spellbook-copy';
import { InventoryItemCard } from './components/inventory-item-card/inventory-item-card';
import { InventoryItemCreator } from './components/inventory-item-creator/inventory-item-creator';

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
  imports: [SpellbookCopy, InventoryItemCard, InventoryItemCreator],
  templateUrl: './character-magic-items.html',
  styleUrl: './character-magic-items.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CharacterMagicItems implements OnInit, OnChanges {
  readonly equipmentChanged = output<void>();
  readonly readonly = input(false);
  readonly accessToken = input.required<string>();
  readonly refreshRevision = input(0);

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['refreshRevision'] && !changes['refreshRevision'].firstChange) {
      this.loadInventory();
    }
  }

  protected onInitialSpellsSaved(): void { this.loadInventory(); }
  protected onInventoryCreated(inventory: CharacterMagicItemInventoryResponse): void {
    this.inventory.set(inventory);
    this.successMessage.set('Inventaire mis à jour.');
    this.equipmentChanged.emit();
  }
  protected onSpellCopied(message: string): void {
    this.successMessage.set(message);
    this.loadInventory();
  }

  private readonly magicItemApi =
    inject(PublicCharacterMagicItemApiService);

  protected readonly inventory =
    signal<CharacterMagicItemInventoryResponse | null>(null);

  protected readonly successMessage = signal<string | null>(null);
  protected readonly loading = signal(true);
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
  protected changeItemQuantity(item: CharacterMagicItemResponse, change: number): void {
    if (this.readonly() || this.updatingItemId() !== null || !item.quantityAdjustable) return;
    if (change === -1 && item.quantity === 1) {
      this.askDiscard(item);
      return;
    }
    this.startUpdate(item.id);
    this.successMessage.set(null);
    this.magicItemApi.update(this.accessToken(), item.id, { quantityChange: change }).subscribe({
      next: response => {
        this.inventory.set(response);
        this.equipmentChanged.emit();
        this.finishUpdate();
      },
      error: (error: HttpErrorResponse) => this.handleError(error),
    });
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
        this.equipmentChanged.emit();
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

  protected canEquip(ownedItem: CharacterMagicItemResponse): boolean {
    return ownedItem.equipped || ownedItem.magicItem.equippable === true;
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
        this.equipmentChanged.emit();
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
          this.equipmentChanged.emit();
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
          this.equipmentChanged.emit();
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
          this.equipmentChanged.emit();
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

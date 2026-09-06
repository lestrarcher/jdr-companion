import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  OnInit,
  signal,
} from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';

import {
  CharacterMagicItemInventoryResponse,
  CharacterMagicItemResponse,
  MagicItemRarity,
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
export class CharacterMagicItems implements OnInit {
  readonly accessToken = input.required<string>();

  private readonly magicItemApi =
    inject(PublicCharacterMagicItemApiService);

  protected readonly inventory =
    signal<CharacterMagicItemInventoryResponse | null>(null);

  protected readonly loading = signal(true);
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

  protected toggleEquipped(
    ownedItem: CharacterMagicItemResponse,
  ): void {
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
    rarity: MagicItemRarity,
  ): string {
    const labels: Record<MagicItemRarity, string> = {
      common: 'Commun',
      uncommon: 'Peu commun',
      rare: 'Rare',
      'very-rare': 'Très rare',
      legendary: 'Légendaire',
      artifact: 'Artefact',
    };

    return labels[rarity];
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

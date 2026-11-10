import { ChangeDetectionStrategy, Component, input, output, signal } from '@angular/core';
import { CharacterMagicItemResponse, MagicItemRarity } from '@core/services/public-character-magic-item-api.service';
import { PactTomeInitial } from '../pact-tome-initial/pact-tome-initial';

@Component({selector:'app-inventory-item-card', standalone:true, imports:[PactTomeInitial], templateUrl:'./inventory-item-card.html', styleUrl:'./inventory-item-card.scss', changeDetection:ChangeDetectionStrategy.OnPush})
export class InventoryItemCard {
  readonly item = input.required<CharacterMagicItemResponse>();
  readonly accessToken = input.required<string>();
  readonly characterId = input<number | null>(null);
  readonly readonly = input(false);
  readonly expanded = input(false);
  readonly updating = input(false);
  readonly anyUpdating = input(false);
  readonly attunedCount = input(0);
  readonly attunementLimit = input(3);
  readonly toggle = output<void>();
  readonly quantityChange = output<number>();
  readonly spendCharge = output<void>();
  readonly restoreCharge = output<void>();
  readonly toggleEquipped = output<void>();
  readonly toggleAttuned = output<void>();
  readonly askConsume = output<void>();
  readonly askDiscard = output<void>();
  readonly saved = output<void>();
  protected readonly initialOpen = signal(false);
  protected rarityClass(rarity: MagicItemRarity | null): string { return rarity ?? 'mundane'; }
  protected rarityLabel(rarity: MagicItemRarity | null): string { const labels: Record<MagicItemRarity,string> = {common:'Commun',uncommon:'Peu commun',rare:'Rare','very-rare':'Très rare',legendary:'Légendaire',artifact:'Artefact'};return rarity === null ? 'Sans rareté' : labels[rarity]; }
  protected formatWeight(grams: number): string { return grams >= 1000 ? `${(grams/1000).toLocaleString('fr-FR',{maximumFractionDigits:2})} kg` : `${grams} g`; }
  protected formatPrice(copper: number | null | undefined): string { if (copper == null) return 'Non renseigné'; if (copper === 0) return '0 pc'; return copper >= 100 ? `${(copper/100).toLocaleString('fr-FR',{maximumFractionDigits:2})} po` : `${copper} pc`; }
  protected canEquip(): boolean { return this.item().equipped || this.item().magicItem.equippable === true; }
  protected canConsume(): boolean { return this.item().magicItem.category === 'potion'; }
  protected hasCharges(): boolean { return this.item().magicItem.maximumCharges !== null; }
  protected canRestoreManually(): boolean { return this.item().magicItem.rechargeType === 'manual'; }
  protected isAttunementDisabled(): boolean { return !this.item().attuned && this.attunedCount() >= this.attunementLimit(); }
}

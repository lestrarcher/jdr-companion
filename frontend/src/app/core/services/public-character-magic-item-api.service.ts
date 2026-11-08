import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

export type AbilityName =
  | 'strength'
  | 'dexterity'
  | 'constitution'
  | 'intelligence'
  | 'wisdom'
  | 'charisma';

export type AbilityEffectOperation =
  | 'bonus'
  | 'minimum'
  | 'permanent-increase';

export type MagicItemRarity =
  | 'common'
  | 'uncommon'
  | 'rare'
  | 'very-rare'
  | 'legendary'
  | 'artifact';

export type MagicItemRechargeType =
  | 'none'
  | 'manual'
  | 'short-rest'
  | 'long-rest'
  | 'daily';

export interface MagicItemAbilityEffectResponse {
  ability: AbilityName;
  operation: AbilityEffectOperation;
  value: number;
  scoreCap: number | null;
  maximumIncrease: number;
}

export interface MagicItemResponse {
  armorType?: 'light' | 'medium' | 'heavy' | 'shield' | null;
  baseArmorClass?: number | null;
  id: number;
  name: string;
  description: string | null;
  rarity: MagicItemRarity | null;
  category: string;
  equippable: boolean;
  priceCopper?: number | null;
  weightGrams?: number | null;
  isSpellScroll?: boolean;
  requiresAttunement: boolean;
  maximumCharges: number | null;
  rechargeType: MagicItemRechargeType;
  rechargeFormula: string | null;
  abilityEffects: MagicItemAbilityEffectResponse[];
}

export interface SpellbookInscriptionResponse {
  id: number;
  spellId: number;
  name: string;
  level: number;
  kind?: 'cantrip' | 'ritual';
}

export interface CharacterMagicItemResponse {
  custom: boolean;
  quantityAdjustable?: boolean;
  spellbookSpells?: SpellbookInscriptionResponse[] | null;
  id: number;
  quantity: number;
  currentCharges: number;
  attuned: boolean;
  equipped: boolean;
  effectActive: boolean;
  notes: string | null;
  inscribedSpellId?: number | null;
  inscribedSpellName?: string | null;
  magicItem: Omit<MagicItemResponse, 'id'> & { id: number | null };
}

export interface EffectiveAbilityResponse {
  ability: AbilityName;
  baseValue: number;
  effectiveValue: number;
  maximumValue: number;
  modifier: number;
}

export interface CharacterMagicItemInventoryResponse {
  characterId: number;
  attunedCount: number;
  attunementLimit: number;
  ownedItems: CharacterMagicItemResponse[];
  effectiveAbilities: EffectiveAbilityResponse[];
}

export interface CharacterMagicItemUpdateResponse
  extends CharacterMagicItemInventoryResponse {
  ownedItem: CharacterMagicItemResponse | null;
}

export interface CharacterMagicItemUpdatePayload {
  quantityChange?: number;
  equipped?: boolean;
  attuned?: boolean;
  chargeChange?: number;
  consume?: boolean;
  discardQuantity?: number;
}

@Injectable({
  providedIn: 'root',
})
export class PublicCharacterMagicItemApiService {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = '/api';

  list(
    accessToken: string,
  ): Observable<CharacterMagicItemInventoryResponse> {
    return this.http.get<CharacterMagicItemInventoryResponse>(
      `${this.apiUrl}/public/characters/${accessToken}/magic-items`,
    );
  }

  catalogue(accessToken: string, query: string, page = 1): Observable<{items: MagicItemResponse[]; total: number; page: number; pageSize: number}> {
    return this.http.get<{items: MagicItemResponse[]; total: number; page: number; pageSize: number}>(
      `${this.apiUrl}/public/characters/${accessToken}/item-catalogue`,
      { params: { q: query, page } },
    );
  }

  acquire(accessToken: string, magicItemId: number, quantity: number, inscribedSpellId?: number): Observable<CharacterMagicItemInventoryResponse> {
    return this.http.post<CharacterMagicItemInventoryResponse>(
      `${this.apiUrl}/public/characters/${accessToken}/magic-items`,
      { magicItemId, quantity, ...(inscribedSpellId != null ? { inscribedSpellId } : {}) },
    );
  }

  createCustom(accessToken: string, name: string, description: string, quantity: number): Observable<CharacterMagicItemInventoryResponse> {
    return this.http.post<CharacterMagicItemInventoryResponse>(
      `${this.apiUrl}/public/characters/${accessToken}/magic-items/custom`,
      { name, description, quantity },
    );
  }

  searchScrollSpells(accessToken: string, query: string): Observable<{spells: {id: number; name: string; level: number}[]}> {
    return this.http.get<{spells: {id: number; name: string; level: number}[]}>(
      `${this.apiUrl}/public/characters/${accessToken}/scroll-spells`,
      { params: { q: query } },
    );
  }

  pactRitualCopyQuote(accessToken: string, params: {sourceOwnedItemId: number; destinationOwnedItemId: number; spellId: number}) {
    return this.http.get<{spellLevel: number; baseCostCp: number; baseMinutes: number; sourceType: string; arcanaDC: number | null}>(
      `${this.apiUrl}/public/characters/${accessToken}/pact-ritual-copy/quote`, {params});
  }

  pactRitualCopy(accessToken: string, payload: {sourceOwnedItemId: number; destinationOwnedItemId: number; spellId: number; confirmed: true; arcanaTotal?: number}) {
    return this.http.post<{success: boolean; scrollConsumed: boolean; walletDebited: boolean}>(
      `${this.apiUrl}/public/characters/${accessToken}/pact-ritual-copy`, payload);
  }

  spellCopyQuote(accessToken: string, params: {policyId: number; sourceOwnedItemId: number; destinationOwnedItemId: number; spellId: number}) {
    return this.http.get<{spellLevel: number; baseCostCp: number; baseMinutes: number; sourceType: string; arcanaDC: number | null}>(
      `${this.apiUrl}/public/characters/${accessToken}/physical-spell-copy/quote`, {params});
  }

  spellCopy(accessToken: string, payload: {policyId: number; sourceOwnedItemId: number; destinationOwnedItemId: number; spellId: number; confirmed: true; arcanaTotal?: number}) {
    return this.http.post<{success: boolean; scrollConsumed: boolean; walletDebited: boolean}>(
      `${this.apiUrl}/public/characters/${accessToken}/physical-spell-copy`, payload);
  }

  update(
    accessToken: string,
    ownedItemId: number,
    payload: CharacterMagicItemUpdatePayload,
  ): Observable<CharacterMagicItemUpdateResponse> {
    return this.http.patch<CharacterMagicItemUpdateResponse>(
      `${this.apiUrl}/public/characters/${accessToken}/magic-items/${ownedItemId}`,
      payload,
    );
  }

  consume(accessToken: string, ownedItemId: number): Observable<CharacterMagicItemUpdateResponse> {
    return this.update(accessToken, ownedItemId, { consume: true });
  }

  discard(accessToken: string, ownedItemId: number, quantity: number): Observable<CharacterMagicItemUpdateResponse> {
    return this.update(accessToken, ownedItemId, { discardQuantity: quantity });
  }

  equip(
    accessToken: string,
    ownedItemId: number,
    equipped: boolean,
  ): Observable<CharacterMagicItemUpdateResponse> {
    return this.update(
      accessToken,
      ownedItemId,
      { equipped },
    );
  }

  attune(
    accessToken: string,
    ownedItemId: number,
    attuned: boolean,
  ): Observable<CharacterMagicItemUpdateResponse> {
    return this.update(
      accessToken,
      ownedItemId,
      { attuned },
    );
  }

  changeCharges(
    accessToken: string,
    ownedItemId: number,
    chargeChange: number,
  ): Observable<CharacterMagicItemUpdateResponse> {
    return this.update(
      accessToken,
      ownedItemId,
      { chargeChange },
    );
  }
}

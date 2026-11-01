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
  id: number;
  name: string;
  description: string | null;
  rarity: MagicItemRarity | null;
  category: string;
  equippable: boolean;
  priceCopper?: number | null;
  weightGrams?: number | null;
  requiresAttunement: boolean;
  maximumCharges: number | null;
  rechargeType: MagicItemRechargeType;
  rechargeFormula: string | null;
  abilityEffects: MagicItemAbilityEffectResponse[];
}

export interface CharacterMagicItemResponse {
  id: number;
  quantity: number;
  currentCharges: number;
  attuned: boolean;
  equipped: boolean;
  effectActive: boolean;
  notes: string | null;
  magicItem: MagicItemResponse;
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

  acquire(accessToken: string, magicItemId: number, quantity: number): Observable<CharacterMagicItemInventoryResponse> {
    return this.http.post<CharacterMagicItemInventoryResponse>(
      `${this.apiUrl}/public/characters/${accessToken}/magic-items`,
      { magicItemId, quantity },
    );
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

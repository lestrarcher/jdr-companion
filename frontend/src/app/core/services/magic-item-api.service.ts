import {
  inject,
  Injectable,
} from '@angular/core';

import {
  HttpClient,
} from '@angular/common/http';

import {
  map,
  Observable,
} from 'rxjs';

import {
  AbilityEffectOperation,
  AbilityName,
  CharacterMagicItemInventoryResponse,
  CharacterMagicItemResponse,
  EffectiveAbilityResponse,
  MagicItemRarity,
  MagicItemRechargeType,
  MagicItemResponse,
} from './public-character-magic-item-api.service';

export interface CreateMagicItemAbilityEffectPayload {
  ability: AbilityName;
  operation: AbilityEffectOperation;
  value: number;
  scoreCap?: number | null;
  maximumIncrease?: number;
}

export interface CreateMagicItemPayload {
  name: string;
  description?: string | null;
  rarity: MagicItemRarity;
  requiresAttunement: boolean;
  maximumCharges?: number | null;
  rechargeType: MagicItemRechargeType;
  rechargeFormula?: string | null;
  abilityEffects:
    CreateMagicItemAbilityEffectPayload[];
}

export interface AssignMagicItemPayload {
  magicItemId: number;
  quantity?: number;
  notes?: string | null;
}

interface MagicItemListApiResponse {
  items: MagicItemResponse[];
}

interface MagicItemCreateApiResponse {
  item: MagicItemResponse;
}

interface OwnedMagicItemApiResponse {
  id: number;
  magicItemId: number;
  name: string;
  description: string | null;
  rarity: MagicItemRarity;
  rarityLabel: string;
  requiresAttunement: boolean;
  attuned: boolean;
  equipped: boolean;
  effectActive: boolean;
  quantity: number;
  currentCharges: number;
  maximumCharges: number | null;
  rechargeType: MagicItemRechargeType;
  rechargeLabel: string;
  rechargeFormula: string | null;
  notes: string | null;
  acquiredAt: string;
  updatedAt: string;
}

interface CharacterInventoryApiResponse {
  characterId: number;
  attunedCount: number;
  attunementLimit: number;
  items: OwnedMagicItemApiResponse[];

  abilities:
    | EffectiveAbilityResponse[]
    | Record<
        string,
        EffectiveAbilityResponse
      >;
}

interface AssignMagicItemApiResponse {
  ownedItem: OwnedMagicItemApiResponse;

  abilities:
    | EffectiveAbilityResponse[]
    | Record<
        string,
        EffectiveAbilityResponse
      >;
}

@Injectable({
  providedIn: 'root',
})
export class MagicItemApiService {
  private readonly http =
    inject(HttpClient);

  private readonly apiUrl = '/api';

  listCatalog(
    campaignId: number,
  ): Observable<MagicItemResponse[]> {
    return this.http
      .get<MagicItemListApiResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/magic-items`,
      )
      .pipe(
        map((response) => response.items),
      );
  }

  create(
    campaignId: number,
    payload: CreateMagicItemPayload,
  ): Observable<MagicItemResponse> {
    return this.http
      .post<MagicItemCreateApiResponse>(
        `${this.apiUrl}/campaigns/${campaignId}/magic-items`,
        payload,
      )
      .pipe(
        map((response) => response.item),
      );
  }

  listCharacterItems(
    characterId: number,
  ): Observable<CharacterMagicItemInventoryResponse> {
    return this.http
      .get<CharacterInventoryApiResponse>(
        `${this.apiUrl}/characters/${characterId}/magic-items`,
      )
      .pipe(
        map((response) =>
          this.normalizeInventory(response),
        ),
      );
  }

  assignToCharacter(
    characterId: number,
    payload: AssignMagicItemPayload,
  ): Observable<CharacterMagicItemResponse> {
    return this.http
      .post<AssignMagicItemApiResponse>(
        `${this.apiUrl}/characters/${characterId}/magic-items`,
        payload,
      )
      .pipe(
        map((response) =>
          this.normalizeOwnedItem(
            response.ownedItem,
          ),
        ),
      );
  }

  removeFromCharacter(
    characterId: number,
    ownedItemId: number,
  ): Observable<void> {
    return this.http.delete<void>(
      `${this.apiUrl}/characters/${characterId}/magic-items/${ownedItemId}`,
    );
  }

  private normalizeInventory(
    response: CharacterInventoryApiResponse,
  ): CharacterMagicItemInventoryResponse {
    const abilities =
      Array.isArray(response.abilities)
        ? response.abilities
        : Object.values(
            response.abilities,
          );

    return {
      characterId:
        response.characterId,

      attunedCount:
        response.attunedCount,

      attunementLimit:
        response.attunementLimit,

      ownedItems:
        response.items.map((item) =>
          this.normalizeOwnedItem(item),
        ),

      effectiveAbilities:
        abilities,
    };
  }

  private normalizeOwnedItem(
    item: OwnedMagicItemApiResponse,
  ): CharacterMagicItemResponse {
    return {
      id: item.id,
      quantity: item.quantity,
      currentCharges:
        item.currentCharges,
      attuned: item.attuned,
      equipped: item.equipped,
      effectActive:
        item.effectActive,
      notes: item.notes,

      magicItem: {
        id: item.magicItemId,
        name: item.name,
        description:
          item.description,
        rarity: item.rarity,

        requiresAttunement:
          item.requiresAttunement,

        maximumCharges:
          item.maximumCharges,

        rechargeType:
          item.rechargeType,

        rechargeFormula:
          item.rechargeFormula,

        /*
         * Le contrôleur d’inventaire ne renvoie
         * pas encore le détail des effets.
         * Ils ne sont pas nécessaires pour
         * afficher les statistiques calculées.
         */
        abilityEffects: [],
      },
    };
  }
}

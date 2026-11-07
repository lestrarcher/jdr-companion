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

export interface MagicItemSpellGrantPayload {
  spellId: number;
  activation: 'possession' | 'equipped' | 'attuned';
  costMode: 'charges' | 'limited';
  cost: number;
  castingLevel?: number | null;
  maxCastingLevel?: number | null;
  usesPerReset: number | null;
  resetTrigger: 'dawn' | 'short-rest' | 'long-rest' | 'manual' | null;
}

export interface MagicItemSpellGrantResponse extends MagicItemSpellGrantPayload {
  id: number;
  spellName: string;
  spellLevel: number;
}

export interface SpellOption { id: number; name: string; level: number; origin: string; ritual?: boolean; }

export interface CreateMagicItemPayload {
  name: string;
  category?: 'other' | 'spellbook';
  description?: string | null;
  rarity: MagicItemRarity;
  requiresAttunement: boolean;
  maximumCharges?: number | null;
  rechargeType: MagicItemRechargeType;
  rechargeFormula?: string | null;
  abilityEffects:
    CreateMagicItemAbilityEffectPayload[];
  spellGrants?: MagicItemSpellGrantPayload[];
}

export interface AssignMagicItemPayload {
  magicItemId: number;
  quantity?: number;
  notes?: string | null;
  inscribedSpellId?: number;
}

interface MagicItemListApiResponse {
  items: MagicItemResponse[];
}

interface MagicItemCreateApiResponse {
  item: MagicItemResponse;
}

interface OwnedMagicItemApiResponse {
  id: number;
  magicItemId: number | null;
  name: string;
  description: string | null;
  rarity: MagicItemRarity | null;
  category: string;
  equippable: boolean;
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

  searchAssignmentCatalog(campaignId: number, query: string, page = 1): Observable<{items: MagicItemResponse[]; total: number; page: number; pageSize: number}> {
    return this.http.get<{items: MagicItemResponse[]; total: number; page: number; pageSize: number}>(
      `${this.apiUrl}/campaigns/${campaignId}/item-catalogue`,
      { params: { q: query, page } },
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

  searchSpells(query: string): Observable<SpellOption[]> {
    return this.http.get<{spells: SpellOption[]}>(`${this.apiUrl}/reference/custom/spells`, {
      params: {q: query, pageSize: 30},
    }).pipe(map(response => response.spells));
  }

  replaceSpellGrants(campaignId: number, itemId: number, spellGrants: MagicItemSpellGrantPayload[]): Observable<MagicItemResponse> {
    return this.http.put<MagicItemCreateApiResponse>(
      `${this.apiUrl}/campaigns/${campaignId}/magic-items/${itemId}/spell-grants`,
      {spellGrants},
    ).pipe(map(response => response.item));
  }

  createReservedSpellbook(campaignId: number, magicItemId: number, spellIds: number[]): Observable<{id: number; name: string; spellCount: number}> {
    return this.http.post<{id: number; name: string; spellCount: number}>(
      `${this.apiUrl}/campaigns/${campaignId}/spellbook-reserve`, {magicItemId, spellIds},
    );
  }

  createReservedPactTome(campaignId: number, magicItemId: number, pactCantripIds: number[], pactRitualIds: number[]): Observable<{id: number; name: string; spellCount: number}> {
    return this.http.post<{id: number; name: string; spellCount: number}>(
      `${this.apiUrl}/campaigns/${campaignId}/spellbook-reserve`,
      {magicItemId, spellIds: [], pactCantripIds, pactRitualIds},
    );
  }

  listSpellbookReserve(campaignId: number): Observable<{books: {id: number; name: string; spellCount: number}[]}> {
    return this.http.get<{books: {id: number; name: string; spellCount: number}[]}>(
      `${this.apiUrl}/campaigns/${campaignId}/spellbook-reserve`,
    );
  }

  assignReservedSpellbook(campaignId: number, bookId: number, characterId: number): Observable<{id: number; characterId: number}> {
    return this.http.post<{id: number; characterId: number}>(
      `${this.apiUrl}/campaigns/${campaignId}/spellbook-reserve/${bookId}/assign`, {characterId},
    );
  }

  rechargeSessionDawn(campaignId: number, sessionId: number): Observable<{recharged: {characters: number; items: number; grants: number}}> {
    return this.http.post<{recharged: {characters: number; items: number; grants: number}}>(
      `${this.apiUrl}/campaigns/${campaignId}/sessions/${sessionId}/magic-items/recharge-dawn`, {},
    );
  }

  rechargeDawn(characterId: number): Observable<{recharged: {items: number; grants: number}}> {
    return this.http.post<{recharged: {items: number; grants: number}}>(
      `${this.apiUrl}/characters/${characterId}/magic-items/recharge-dawn`, {},
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

  searchScrollSpells(characterId: number, query: string): Observable<{spells: {id: number; name: string; level: number}[]}> {
    return this.http.get<{spells: {id: number; name: string; level: number}[]}>(
      `${this.apiUrl}/characters/${characterId}/scroll-spells`,
      { params: { q: query } },
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

  createCharacterSpellbook(characterId: number, payload: {magicItemId: number; spellIds: number[]; notes?: string | null}): Observable<{ownedItemId: number; magicItemId: number; spells: unknown[]}> {
    return this.http.post<{ownedItemId: number; magicItemId: number; spells: unknown[]}>(
      `${this.apiUrl}/characters/${characterId}/spellbooks`, payload,
    );
  }

  removeFromCharacter(
    characterId: number,
    ownedItemId: number,
    quantity = 1,
  ): Observable<void> {
    return this.http.delete<void>(
      `${this.apiUrl}/characters/${characterId}/magic-items/${ownedItemId}`,
      { params: { quantity } },
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
      custom: item.magicItemId === null,
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
        category: item.category,
        equippable: item.equippable,

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

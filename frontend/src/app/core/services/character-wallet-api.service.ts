import {
  Injectable,
  inject,
} from '@angular/core';
import { HttpClient } from '@angular/common/http';
import {
  Observable,
  map,
} from 'rxjs';

export type WalletField =
  | 'copperPieces'
  | 'silverPieces'
  | 'electrumPieces'
  | 'goldPieces'
  | 'platinumPieces';

export interface CharacterWallet {
  id: number;
  characterId: number;
  copperPieces: number;
  silverPieces: number;
  electrumPieces: number;
  goldPieces: number;
  platinumPieces: number;
  updatedAt: string;
}

export type CharacterWalletChanges =
  Partial<
    Record<WalletField, number>
  >;

interface CharacterWalletResponse {
  wallet: CharacterWallet;
}

@Injectable({
  providedIn: 'root',
})
export class CharacterWalletApiService {
  private readonly http =
    inject(HttpClient);

  private readonly apiUrl = '/api';

  get(
    accessToken: string,
  ): Observable<CharacterWallet> {
    return this.http
      .get<CharacterWalletResponse>(
        `${this.apiUrl}/public/characters/${accessToken}/wallet`,
      )
      .pipe(
        map(
          (response) =>
            response.wallet,
        ),
      );
  }

  update(
    accessToken: string,
    changes: CharacterWalletChanges,
  ): Observable<CharacterWallet> {
    return this.http
      .patch<CharacterWalletResponse>(
        `${this.apiUrl}/public/characters/${accessToken}/wallet`,
        changes,
      )
      .pipe(
        map(
          (response) =>
            response.wallet,
        ),
      );
  }
}

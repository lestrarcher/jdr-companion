import {
  Component,
  OnInit,
  computed,
  inject,
  input,
  signal,
} from '@angular/core';
import {
  FormControl,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { finalize } from 'rxjs';

import {
  CharacterWallet,
  CharacterWalletApiService,
  WalletField,
} from '@core/services/character-wallet-api.service';

interface CurrencyDefinition {
  field: WalletField;
  abbreviation: string;
  label: string;
}

@Component({
  selector: 'app-character-wallet',
  imports: [
    ReactiveFormsModule,
  ],
  templateUrl: './character-wallet.html',
  styleUrl: './character-wallet.scss',
})
export class CharacterWalletComponent
  implements OnInit
{
  readonly accessToken =
    input.required<string>();

  readonly disabled =
    input(false);

  private readonly walletApi =
    inject(CharacterWalletApiService);

  protected readonly wallet =
    signal<CharacterWallet | null>(null);

  protected readonly loading =
    signal(true);

  protected readonly saving =
    signal(false);

  protected readonly error =
    signal<string | null>(null);

  protected readonly selectedField =
    signal<WalletField>('goldPieces');

  protected readonly currencies:
    CurrencyDefinition[] = [
      {
        field: 'copperPieces',
        abbreviation: 'PC',
        label: 'Cuivre',
      },
      {
        field: 'silverPieces',
        abbreviation: 'PA',
        label: 'Argent',
      },
      {
        field: 'electrumPieces',
        abbreviation: 'PE',
        label: 'Électrum',
      },
      {
        field: 'goldPieces',
        abbreviation: 'PO',
        label: 'Or',
      },
      {
        field: 'platinumPieces',
        abbreviation: 'PP',
        label: 'Platine',
      },
    ];

  protected readonly amountControl =
    new FormControl<number>(
      0,
      {
        nonNullable: true,
        validators: [
          Validators.required,
          Validators.min(1),
          Validators.pattern(/^\d+$/),
        ],
      },
    );

  protected readonly selectedCurrency =
    computed(() =>
      this.currencies.find(
        (currency) =>
          currency.field
          === this.selectedField(),
      ) ?? this.currencies[3],
    );

  protected readonly selectedBalance =
    computed(() => {
      const wallet = this.wallet();

      if (!wallet) {
        return 0;
      }

      return wallet[
        this.selectedField()
      ];
    });

  ngOnInit(): void {
    this.loadWallet();
  }

  protected selectCurrency(
    field: WalletField,
  ): void {
    if (this.saving()) {
      return;
    }

    this.selectedField.set(field);
    this.error.set(null);
  }

  protected addMoney(): void {
    this.applyChange(1);
  }

  protected spendMoney(): void {
    this.applyChange(-1);
  }

  private loadWallet(): void {
    this.loading.set(true);
    this.error.set(null);

    this.walletApi
      .get(this.accessToken())
      .pipe(
        finalize(() => {
          this.loading.set(false);
        }),
      )
      .subscribe({
        next: (wallet) => {
          this.wallet.set(wallet);
        },

        error: (error: any) => {
          console.error(
            'Impossible de charger la bourse.',
            error,
          );

          this.error.set(
            error?.error?.message
            ?? 'La bourse est indisponible.',
          );
        },
      });
  }

  private applyChange(
    direction: 1 | -1,
  ): void {
    if (
      this.disabled()
      || this.saving()
    ) {
      return;
    }

    if (this.amountControl.invalid) {
      this.amountControl.markAsTouched();

      this.error.set(
        'Saisis un nombre entier supérieur à zéro.',
      );

      return;
    }

    const amount =
      this.amountControl.value;

    if (
      direction === -1
      && amount > this.selectedBalance()
    ) {
      this.error.set(
        'Tu ne possèdes pas assez de pièces.',
      );

      return;
    }

    const field =
      this.selectedField();

    this.saving.set(true);
    this.error.set(null);

    this.walletApi
      .update(
        this.accessToken(),
        {
          [field]:
            amount * direction,
        },
      )
      .pipe(
        finalize(() => {
          this.saving.set(false);
        }),
      )
      .subscribe({
        next: (wallet) => {
          this.wallet.set(wallet);

          this.amountControl.setValue(0);
          this.amountControl.markAsPristine();
          this.amountControl.markAsUntouched();
        },

        error: (error: any) => {
          console.error(
            'Impossible de modifier la bourse.',
            error,
          );

          this.error.set(
            error?.error?.message
            ?? 'La bourse n’a pas pu être modifiée.',
          );
        },
      });
  }
}

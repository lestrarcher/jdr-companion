import {
  ChangeDetectionStrategy,
  Component,
  inject,
  input,
  OnInit,
  output,
  signal,
} from '@angular/core';
import {
  FormArray,
  FormBuilder,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';

import {
  CreateMagicItemPayload,
  MagicItemApiService,
} from '@core/services/magic-item-api.service';

import {
  AbilityEffectOperation,
  AbilityName,
  MagicItemRarity,
  MagicItemRechargeType,
  MagicItemResponse,
} from '@core/services/public-character-magic-item-api.service';

@Component({
  selector: 'app-magic-item-manager',
  standalone: true,
  imports: [
    ReactiveFormsModule,
  ],
  templateUrl: './magic-item-manager.html',
  styleUrl: './magic-item-manager.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MagicItemManager implements OnInit {
  readonly campaignId = input.required<number>();

  readonly catalogChanged =
    output<MagicItemResponse[]>();

  private readonly formBuilder = inject(FormBuilder);
  private readonly magicItemApi =
    inject(MagicItemApiService);

  protected readonly catalog =
    signal<MagicItemResponse[]>([]);

  protected readonly loading = signal(true);
  protected readonly submitting = signal(false);
  protected readonly errorMessage =
    signal<string | null>(null);

  protected readonly successMessage =
    signal<string | null>(null);

  protected readonly rarities: Array<{
    value: MagicItemRarity;
    label: string;
  }> = [
    {
      value: 'common',
      label: 'Commun',
    },
    {
      value: 'uncommon',
      label: 'Peu commun',
    },
    {
      value: 'rare',
      label: 'Rare',
    },
    {
      value: 'very-rare',
      label: 'Très rare',
    },
    {
      value: 'legendary',
      label: 'Légendaire',
    },
    {
      value: 'artifact',
      label: 'Artefact',
    },
  ];

  protected readonly rechargeTypes: Array<{
    value: MagicItemRechargeType;
    label: string;
  }> = [
    {
      value: 'none',
      label: 'Aucune recharge',
    },
    {
      value: 'manual',
      label: 'Recharge manuelle',
    },
    {
      value: 'short-rest',
      label: 'Repos court',
    },
    {
      value: 'long-rest',
      label: 'Repos long',
    },
    {
      value: 'daily',
      label: 'Chaque jour',
    },
  ];

  protected readonly abilities: Array<{
    value: AbilityName;
    label: string;
  }> = [
    {
      value: 'strength',
      label: 'Force',
    },
    {
      value: 'dexterity',
      label: 'Dextérité',
    },
    {
      value: 'constitution',
      label: 'Constitution',
    },
    {
      value: 'intelligence',
      label: 'Intelligence',
    },
    {
      value: 'wisdom',
      label: 'Sagesse',
    },
    {
      value: 'charisma',
      label: 'Charisme',
    },
  ];

  protected readonly operations: Array<{
    value: AbilityEffectOperation;
    label: string;
  }> = [
    {
      value: 'bonus',
      label: 'Ajouter un bonus',
    },
    {
      value: 'minimum',
      label: 'Fixer une valeur minimale',
    },
    {
      value: 'permanent-increase',
      label: 'Augmentation permanente',
    },
  ];

  protected readonly form = this.formBuilder.group({
    name: [
      '',
      [
        Validators.required,
        Validators.maxLength(150),
      ],
    ],

    description: [''],

    rarity: [
      'uncommon' as MagicItemRarity,
      Validators.required,
    ],

    requiresAttunement: [false],

    maximumCharges: [
      null as number | null,
      [
        Validators.min(1),
        Validators.max(999),
      ],
    ],

    rechargeType: [
      'none' as MagicItemRechargeType,
      Validators.required,
    ],

    rechargeFormula: [''],

    abilityEffects: this.formBuilder.array([]),
  });

  protected get abilityEffects(): FormArray {
    return this.form.controls.abilityEffects;
  }

  ngOnInit(): void {
    this.loadCatalog();
  }

  protected addAbilityEffect(): void {
    this.abilityEffects.push(
      this.formBuilder.group({
        ability: [
          'strength' as AbilityName,
          Validators.required,
        ],

        operation: [
          'bonus' as AbilityEffectOperation,
          Validators.required,
        ],

        value: [
          1,
          [
            Validators.required,
            Validators.min(1),
            Validators.max(30),
          ],
        ],

        scoreCap: [
          null as number | null,
          [
            Validators.min(1),
            Validators.max(30),
          ],
        ],

        maximumIncrease: [
          0,
          [
            Validators.min(0),
            Validators.max(30),
          ],
        ],
      }),
    );
  }

  protected removeAbilityEffect(
    index: number,
  ): void {
    this.abilityEffects.removeAt(index);
  }

  protected submit(): void {
    this.errorMessage.set(null);
    this.successMessage.set(null);

    if (this.form.invalid) {
      this.form.markAllAsTouched();

      this.errorMessage.set(
        'Certains champs du formulaire sont invalides.',
      );

      return;
    }

    const rawValue = this.form.getRawValue();

    const rawAbilityEffects = rawValue.abilityEffects as Array<{
      ability: AbilityName;
      operation: AbilityEffectOperation;
      value: number;
      scoreCap: number | null;
      maximumIncrease: number;
    }>;

    const payload: CreateMagicItemPayload = {
      name: rawValue.name!.trim(),

      description:
        rawValue.description?.trim() || null,

      rarity: rawValue.rarity!,

      requiresAttunement:
        rawValue.requiresAttunement ?? false,

      maximumCharges:
        rawValue.maximumCharges ?? null,

      rechargeType:
        rawValue.maximumCharges === null
          ? 'none'
          : rawValue.rechargeType!,

      rechargeFormula:
        rawValue.maximumCharges === null
          ? null
          : rawValue.rechargeFormula?.trim()
            || null,

      abilityEffects:
        rawAbilityEffects.map(
          (effect) => ({
            ability: effect.ability,
            operation: effect.operation,
            value: effect.value,
            scoreCap:
              effect.scoreCap ?? null,
            maximumIncrease:
              effect.maximumIncrease ?? 0,
          }),
        ),
    };

    this.submitting.set(true);

    this.magicItemApi
      .create(
        this.campaignId(),
        payload,
      )
      .subscribe({
        next: (magicItem) => {
          const updatedCatalog = [
            ...this.catalog(),
            magicItem,
          ].sort((first, second) =>
            first.name.localeCompare(
              second.name,
              'fr',
            ),
          );

          this.catalog.set(updatedCatalog);
          this.catalogChanged.emit(updatedCatalog);

          this.resetForm();

          this.successMessage.set(
            `${magicItem.name} a été ajouté au catalogue.`,
          );

          this.submitting.set(false);
        },

        error: (error: HttpErrorResponse) => {
          this.errorMessage.set(
            typeof error.error?.message === 'string'
              ? error.error.message
              : 'Impossible de créer cet objet magique.',
          );

          this.submitting.set(false);
        },
      });
  }

  protected rarityLabel(
    rarity: MagicItemRarity,
  ): string {
    return (
      this.rarities.find(
        (candidate) =>
          candidate.value === rarity,
      )?.label ?? rarity
    );
  }

  protected rechargeLabel(
    rechargeType: MagicItemRechargeType,
  ): string {
    return (
      this.rechargeTypes.find(
        (candidate) =>
          candidate.value === rechargeType,
      )?.label ?? rechargeType
    );
  }

  private loadCatalog(): void {
    this.loading.set(true);
    this.errorMessage.set(null);

    this.magicItemApi
      .listCatalog(this.campaignId())
      .subscribe({
        next: (catalog) => {
          const sortedCatalog = [
            ...catalog,
          ].sort((first, second) =>
            first.name.localeCompare(
              second.name,
              'fr',
            ),
          );

          this.catalog.set(sortedCatalog);
          this.catalogChanged.emit(sortedCatalog);
          this.loading.set(false);
        },

        error: (error: HttpErrorResponse) => {
          this.errorMessage.set(
            typeof error.error?.message === 'string'
              ? error.error.message
              : 'Impossible de charger le catalogue.',
          );

          this.loading.set(false);
        },
      });
  }

  private resetForm(): void {
    this.form.reset({
      name: '',
      description: '',
      rarity: 'uncommon',
      requiresAttunement: false,
      maximumCharges: null,
      rechargeType: 'none',
      rechargeFormula: '',
    });

    this.abilityEffects.clear();
  }
}

import {
  Component,
  inject,
  signal,
} from '@angular/core';
import {
  FormBuilder,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import {
  CampaignApiResponse,
  CampaignApiService,
  CampaignConfigurationKey,
} from '@core/services/campaign-api.service';

interface CampaignConfigurationOption {
  key: CampaignConfigurationKey;
  label: string;
  description: string;
  available: boolean;
}

@Component({
  selector: 'app-campaign-list',
  imports: [
    ReactiveFormsModule,
    RouterLink,
  ],
  templateUrl: './campaign-list.html',
  styleUrl: './campaign-list.scss',
})
export class CampaignList {
  private readonly formBuilder =
    inject(FormBuilder);

  private readonly campaignApi =
    inject(CampaignApiService);

  protected readonly campaigns =
    signal<CampaignApiResponse[]>([]);

  protected readonly loading = signal(true);
  protected readonly creating = signal(false);

  protected readonly error =
    signal<string | null>(null);

  protected readonly configurationOptions:
    readonly CampaignConfigurationOption[] = [
      {
        key: 'strahd',
        label: 'La Malédiction de Strahd',
        description:
          'Interface brumeuse de Barovie, quêtes et mémorial.',
        available: true,
      },
      {
        key: 'vecna',
        label: 'Vecna : au seuil du néant',
        description:
          'Configuration prévue pour la campagne de Vecna.',
        available: true,
      },
    ];

  protected readonly campaignForm =
    this.formBuilder.nonNullable.group({
      name: [
        '',
        [
          Validators.required,
          Validators.minLength(3),
        ],
      ],

      configurationKey:
        this.formBuilder.nonNullable.control<
          CampaignConfigurationKey
        >('strahd'),
    });

  constructor() {
    this.loadCampaigns();
  }

  protected createCampaign(): void {
    if (
      this.campaignForm.invalid ||
      this.creating()
    ) {
      this.campaignForm.markAllAsTouched();
      return;
    }

    const name =
      this.campaignForm.controls.name.value.trim();

    const configurationKey =
      this.campaignForm.controls
        .configurationKey.value;

    this.creating.set(true);
    this.error.set(null);

    this.campaignApi
      .create({
        name,
        configurationKey,
        slug: this.createSlug(name),
      })
      .pipe(
        finalize(() => {
          this.creating.set(false);
        }),
      )
      .subscribe({
        next: (campaign) => {
          this.campaigns.update(
            (campaigns) => [
              ...campaigns,
              campaign,
            ],
          );

          this.campaignForm.reset({
            name: '',
            configurationKey: 'strahd',
          });
        },

        error: (error: any) => {
          console.error(
            'Impossible de créer la campagne.',
            error,
          );

          this.error.set(
            error?.error?.message ??
              'La campagne n’a pas pu être créée.',
          );
        },
      });
  }

  protected getConfigurationLabel(
    configurationKey: CampaignConfigurationKey,
  ): string {
    return (
      this.configurationOptions.find(
        (option) =>
          option.key === configurationKey,
      )?.label ?? configurationKey
    );
  }

  private loadCampaigns(): void {
    this.loading.set(true);
    this.error.set(null);

    this.campaignApi
      .list()
      .pipe(
        finalize(() => {
          this.loading.set(false);
        }),
      )
      .subscribe({
        next: (campaigns) => {
          this.campaigns.set(campaigns);
        },

        error: (error: any) => {
          console.error(
            'Impossible de charger les campagnes.',
            error,
          );

          this.error.set(
            error?.error?.message ??
              'Les campagnes n’ont pas pu être chargées.',
          );
        },
      });
  }

  private createSlug(value: string): string {
    return value
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }
}

import { CampaignItemCatalogueApiService } from '@core/services/campaign-item-catalogue-api.service';

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
import {
  ActivatedRoute,
  Router,
  RouterLink,
} from '@angular/router';
import {
  finalize,
  forkJoin,
} from 'rxjs';

import {
  CampaignApiResponse,
  CampaignApiService,
} from '@core/services/campaign-api.service';
import {
  GameSessionApiResponse,
  GameSessionApiService,
  GameSessionStatus,
} from '@core/services/game-session-api.service';

@Component({
  selector: 'app-campaign-detail',
  imports: [
    ReactiveFormsModule,
    RouterLink,
  ],
  templateUrl: './campaign-detail.html',
  styleUrl: './campaign-detail.scss',
})
export class CampaignDetail {
  private readonly route =
    inject(ActivatedRoute);

  private readonly router =
    inject(Router);

  private readonly formBuilder =
    inject(FormBuilder);

  private readonly itemCatalogueApi = inject(CampaignItemCatalogueApiService);

  protected readonly rarityOptions = [
    { value: 'common', label: 'Commun' },
    { value: 'uncommon', label: 'Peu commun' },
    { value: 'rare', label: 'Rare' },
    { value: 'very-rare', label: 'Très rare' },
    { value: 'legendary', label: 'Légendaire' },
    { value: 'artifact', label: 'Artefact' },
  ];
  protected readonly allowedRarities = signal<string[]>([]);
  protected readonly raritySaving = signal(false);
  protected readonly rarityMessage = signal<string | null>(null);

  protected toggleRarity(value: string, enabled: boolean): void {
    this.allowedRarities.update(current => enabled
      ? [...current.filter(item => item !== value), value]
      : current.filter(item => item !== value));
    this.rarityMessage.set(null);
  }

  protected saveRarities(): void {
    if (this.raritySaving()) return;
    this.raritySaving.set(true);
    this.rarityMessage.set(null);
    this.itemCatalogueApi.saveRarities(this.campaignId, this.allowedRarities())
      .pipe(finalize(() => this.raritySaving.set(false)))
      .subscribe({
        next: response => {
          this.allowedRarities.set(response.rarities);
          this.rarityMessage.set('Catalogue de campagne enregistré.');
        },
        error: () => this.rarityMessage.set('Impossible de sauvegarder les raretés.'),
      });
  }

  private loadRarities(): void {
    this.itemCatalogueApi.getRarities(this.campaignId).subscribe({
      next: response => this.allowedRarities.set(response.rarities),
      error: () => this.rarityMessage.set('Impossible de charger les raretés.'),
    });
  }

  private readonly campaignApi =
    inject(CampaignApiService);

  private readonly gameSessionApi =
    inject(GameSessionApiService);

  protected readonly campaign =
    signal<CampaignApiResponse | null>(null);

  protected readonly sessions =
    signal<GameSessionApiResponse[]>([]);

  protected readonly loading = signal(true);
  protected readonly creating = signal(false);

  protected readonly error =
    signal<string | null>(null);

  protected readonly sessionForm =
    this.formBuilder.nonNullable.group({
      name: [
        '',
        [
          Validators.required,
          Validators.minLength(3),
        ],
      ],
    });

  private readonly campaignId: number;

  constructor() {
    const campaignId = Number(
      this.route.snapshot.paramMap.get(
        'campaignId',
      ),
    );

    if (
      !Number.isInteger(campaignId) ||
      campaignId <= 0
    ) {
      throw new Error(
        'Identifiant de campagne invalide.',
      );
    }

    this.campaignId = campaignId;
    this.loadCampaign();
    this.loadRarities();
  }

  protected createSession(): void {
    if (
      this.sessionForm.invalid ||
      this.creating()
    ) {
      this.sessionForm.markAllAsTouched();
      return;
    }

    const name =
      this.sessionForm.controls.name.value.trim();

    this.creating.set(true);
    this.error.set(null);

    this.gameSessionApi
      .create(this.campaignId, {
        name,
        slug: this.createSlug(name),
      })
      .pipe(
        finalize(() => {
          this.creating.set(false);
        }),
      )
      .subscribe({
        next: (session) => {
          this.router.navigate([
            '/campaigns',
            this.campaignId,
            'sessions',
            session.id,
            'control',
          ]);
        },

        error: (error: any) => {
          console.error(
            'Impossible de créer la session.',
            error,
          );

          this.error.set(
            error?.error?.message ??
              'La session n’a pas pu être créée.',
          );
        },
      });
  }

  protected sessionStatusLabel(
    status: GameSessionStatus,
  ): string {
    switch (status) {
      case 'live':
        return 'En cours';

      case 'closed':
        return 'Terminée';

      case 'draft':
      default:
        return 'En préparation';
    }
  }

  private loadCampaign(): void {
    this.loading.set(true);
    this.error.set(null);

    forkJoin({
      campaigns: this.campaignApi.list(),

      sessions:
        this.gameSessionApi.list(
          this.campaignId,
        ),
    })
      .pipe(
        finalize(() => {
          this.loading.set(false);
        }),
      )
      .subscribe({
        next: ({
          campaigns,
          sessions,
        }) => {
          const campaign =
            campaigns.find(
              (candidate) =>
                candidate.id ===
                this.campaignId,
            );

          if (!campaign) {
            this.error.set(
              'Campagne introuvable.',
            );

            return;
          }

          this.campaign.set(campaign);

          this.sessions.set(
            [...sessions].sort(
              (first, second) =>
                new Date(
                  second.createdAt,
                ).getTime() -
                new Date(
                  first.createdAt,
                ).getTime(),
            ),
          );
        },

        error: (error: any) => {
          console.error(
            'Impossible de charger la campagne.',
            error,
          );

          this.error.set(
            error?.error?.message ??
              'La campagne n’a pas pu être chargée.',
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

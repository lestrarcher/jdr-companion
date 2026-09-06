import {
  Component,
  OnDestroy,
  OnInit,
  computed,
  inject,
  input,
  signal,
} from '@angular/core';
import { finalize } from 'rxjs';

import {
  CampaignFigure,
  CampaignFigureApiService,
} from '@core/services/campaign-figure-api.service';
import {
  FigurePanelMode,
} from '@core/models/live-session-state.model';

@Component({
  selector: 'app-memorial-panel',
  imports: [],
  templateUrl: './memorial-panel.html',
  styleUrl: './memorial-panel.scss',
})
export class MemorialPanel
  implements OnInit, OnDestroy
{
  readonly campaignId = input.required<number>();

  readonly mode =
    input<FigurePanelMode>('memorial');

  private readonly figureApi =
    inject(CampaignFigureApiService);

  protected readonly figures =
    signal<CampaignFigure[]>([]);

  protected readonly loading = signal(true);

  protected readonly error =
    signal<string | null>(null);

  protected readonly activeIndex = signal(0);

  protected readonly displayedFigures = computed(() => {
    const mode = this.mode();

    if (mode === 'hidden') {
      return [];
    }

    return this.figures().filter((figure) => {
      if (
        figure.publicationStatus !== 'visible'
        || figure.encounterStatus !== 'met'
      ) {
        return false;
      }

      if (mode === 'party') {
        return (
          figure.characterType === 'pc'
          && figure.lifeStatus === 'alive'
        );
      }

      if (mode === 'important-npcs') {
        return (
          figure.characterType === 'npc'
          && figure.lifeStatus === 'alive'
        );
      }

      return figure.lifeStatus === 'dead';
    });
  });

  protected readonly activeFigure = computed(() => {
    const figures = this.displayedFigures();

    if (figures.length === 0) {
      return null;
    }

    return figures[
      this.activeIndex() % figures.length
    ];
  });

  protected readonly eyebrow = computed(() => {
    switch (this.mode()) {
      case 'party':
        return 'Compagnons de route';
      case 'important-npcs':
        return 'Visages de la campagne';
      case 'memorial':
        return 'Ceux que la Brume a pris';
      default:
        return '';
    }
  });

  protected readonly title = computed(() => {
    switch (this.mode()) {
      case 'party':
        return 'Les aventuriers';
      case 'important-npcs':
        return 'PNJ importants';
      case 'memorial':
        return 'In memoriam';
      default:
        return '';
    }
  });

  private rotationTimer:
    ReturnType<typeof setInterval> | null = null;

  ngOnInit(): void {
    this.loadFigures();
    this.startRotation();
  }

  ngOnDestroy(): void {
    if (this.rotationTimer !== null) {
      clearInterval(this.rotationTimer);
    }
  }

  protected roleLabel(
    figure: CampaignFigure,
  ): string {
    return figure.characterType === 'pc'
      ? 'PJ'
      : 'PNJ';
  }

  private loadFigures(): void {
    this.loading.set(true);
    this.error.set(null);

    this.figureApi
      .list(this.campaignId())
      .pipe(
        finalize(() => {
          this.loading.set(false);
        }),
      )
      .subscribe({
        next: (figures) => {
          this.figures.set(figures);
          this.activeIndex.set(
            this.randomInitialIndex(figures.length),
          );
        },

        error: (error: any) => {
          console.error(
            'Impossible de charger les figures de campagne.',
            error,
          );

          this.error.set(
            error?.error?.message
              ?? 'Les figures de campagne sont indisponibles.',
          );
        },
      });
  }

  private startRotation(): void {
    this.rotationTimer = setInterval(() => {
      const entryCount =
        this.displayedFigures().length;

      this.activeIndex.update((currentIndex) =>
        this.pickRandomIndex(
          currentIndex,
          entryCount,
        ),
      );
    }, 9000);
  }

  private randomInitialIndex(
    entryCount: number,
  ): number {
    return entryCount > 0
      ? Math.floor(Math.random() * entryCount)
      : 0;
  }

  private pickRandomIndex(
    currentIndex: number,
    entryCount: number,
  ): number {
    if (entryCount <= 1) {
      return 0;
    }

    const normalizedIndex =
      currentIndex % entryCount;

    const randomOffset =
      Math.floor(Math.random() * (entryCount - 1)) + 1;

    return (
      normalizedIndex + randomOffset
    ) % entryCount;
  }
}

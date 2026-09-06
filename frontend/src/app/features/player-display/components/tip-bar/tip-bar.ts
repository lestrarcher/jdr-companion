import {
  Component,
  DestroyRef,
  OnInit,
  computed,
  inject,
  input,
  signal,
} from '@angular/core';
import { finalize } from 'rxjs';

import {
  CampaignTip,
  TipApiService,
} from '@core/services/tip-api.service';

@Component({
  selector: 'app-tip-bar',
  imports: [],
  templateUrl: './tip-bar.html',
  styleUrl: './tip-bar.scss',
})
export class TipBar implements OnInit {
  readonly campaignId =
    input.required<number>();

  private readonly destroyRef =
    inject(DestroyRef);

  private readonly tipApi =
    inject(TipApiService);

  protected readonly tips =
    signal<CampaignTip[]>([]);

  protected readonly loading =
    signal(true);

  protected readonly currentIndex =
    signal(0);

  protected readonly visibleTips =
    computed(() =>
      this.tips()
        .filter(
          (tip) =>
            tip.status === 'visible',
        )
        .sort(
          (firstTip, secondTip) =>
            firstTip.displayOrder
            - secondTip.displayOrder,
        ),
    );

  protected readonly currentTip =
    computed(() => {
      const tips =
        this.visibleTips();

      if (tips.length === 0) {
        return null;
      }

      return tips[
        this.currentIndex()
        % tips.length
      ];
    });

  public constructor() {
    const rotationTimer =
      window.setInterval(() => {
        this.showNextTip();
      }, 8_000);

    this.destroyRef.onDestroy(() => {
      window.clearInterval(
        rotationTimer,
      );
    });
  }

  ngOnInit(): void {
    this.loadTips();
  }

  private loadTips(): void {
    this.loading.set(true);

    this.tipApi
      .list(this.campaignId())
      .pipe(
        finalize(() => {
          this.loading.set(false);
        }),
      )
      .subscribe({
        next: (tips) => {
          this.tips.set(tips);
          this.currentIndex.set(0);
        },

        error: (error: unknown) => {
          console.error(
            'Impossible de charger les tips.',
            error,
          );

          /*
           * Le display ne montre pas de message
           * technique aux joueurs. La barre reste
           * simplement masquée.
           */
          this.tips.set([]);
        },
      });
  }

  private showNextTip(): void {
    const tipCount =
      this.visibleTips().length;

    if (tipCount <= 1) {
      return;
    }

    this.currentIndex.update(
      (currentIndex) =>
        (currentIndex + 1) % tipCount,
    );
  }
}

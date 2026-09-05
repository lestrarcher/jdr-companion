import {
  Component,
  OnInit,
  inject,
  input,
  signal,
} from '@angular/core';
import { finalize } from 'rxjs';

import {
  CampaignQuest,
  QuestApiService,
} from '@core/services/quest-api.service';

@Component({
  selector: 'app-quests-panel',
  imports: [],
  templateUrl: './quests-panel.html',
  styleUrl: './quests-panel.scss',
})
export class QuestsPanel implements OnInit {
  readonly campaignId =
    input.required<number>();

  private readonly questApi =
    inject(QuestApiService);

  protected readonly quests =
    signal<CampaignQuest[]>([]);

  protected readonly loading =
    signal(true);

  protected readonly error =
    signal<string | null>(null);

  ngOnInit(): void {
    this.loadQuests();
  }

  protected get scrollDuration(): string {
    return `${
      Math.max(
        this.quests().length * 7,
        30,
      )
    }s`;
  }

  private loadQuests(): void {
    this.loading.set(true);
    this.error.set(null);

    this.questApi
      .list(this.campaignId())
      .pipe(
        finalize(() => {
          this.loading.set(false);
        }),
      )
      .subscribe({
        next: (quests) => {
          this.quests.set(
            quests
              .filter(
                (quest) =>
                  quest.status === 'active',
              )
              .sort(
                (first, second) =>
                  first.displayOrder -
                  second.displayOrder,
              ),
          );
        },

        error: (error: any) => {
          console.error(
            'Impossible de charger les quêtes.',
            error,
          );

          this.error.set(
            error?.error?.message ??
              'Les quêtes sont indisponibles.',
          );
        },
      });
  }
}

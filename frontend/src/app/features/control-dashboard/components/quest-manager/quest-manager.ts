import {
  Component,
  OnInit,
  inject,
  input,
  signal,
} from '@angular/core';
import {
  FormBuilder,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import {
  finalize,
  forkJoin,
} from 'rxjs';

import {
  CampaignQuest,
  QuestApiService,
  QuestStatus,
} from '@core/services/quest-api.service';

@Component({
  selector: 'app-quest-manager',
  imports: [
    ReactiveFormsModule,
  ],
  templateUrl: './quest-manager.html',
  styleUrl: './quest-manager.scss',
})
export class QuestManager implements OnInit {
  readonly campaignId =
    input.required<number>();

  private readonly formBuilder =
    inject(FormBuilder);

  private readonly questApi =
    inject(QuestApiService);

  protected readonly quests =
    signal<CampaignQuest[]>([]);

  protected readonly loading =
    signal(true);

  protected readonly saving =
    signal(false);

  protected readonly error =
    signal<string | null>(null);

  protected readonly editingQuestId =
    signal<number | null>(null);

  protected readonly questForm =
    this.formBuilder.nonNullable.group({
      title: [
        '',
        [
          Validators.required,
          Validators.maxLength(150),
        ],
      ],

      category: [
        '',
        [
          Validators.required,
          Validators.maxLength(80),
        ],
      ],

      objective: [
        '',
        [
          Validators.required,
        ],
      ],
    });

  ngOnInit(): void {
    this.loadQuests();
  }

  protected submitQuest(): void {
    if (
      this.questForm.invalid
      || this.saving()
    ) {
      this.questForm.markAllAsTouched();

      return;
    }

    const payload =
      this.questForm.getRawValue();

    const editingId =
      this.editingQuestId();

    this.saving.set(true);
    this.error.set(null);

    const request$ = editingId === null
      ? this.questApi.create(
          this.campaignId(),
          payload,
        )
      : this.questApi.update(
          editingId,
          payload,
        );

    request$
      .pipe(
        finalize(() => {
          this.saving.set(false);
        }),
      )
      .subscribe({
        next: (quest) => {
          if (editingId === null) {
            this.quests.update(
              (quests) =>
                this.sortQuests([
                  ...quests,
                  quest,
                ]),
            );
          } else {
            this.replaceQuest(quest);
          }

          this.resetForm();
        },

        error: (error: any) => {
          console.error(
            'Impossible d’enregistrer la quête.',
            error,
          );

          this.error.set(
            error?.error?.message ??
              'La quête n’a pas pu être enregistrée.',
          );
        },
      });
  }

  protected editQuest(
    quest: CampaignQuest,
  ): void {
    this.editingQuestId.set(quest.id);

    this.questForm.setValue({
      title: quest.title,
      category: quest.category,
      objective: quest.objective,
    });
  }

  protected cancelEdition(): void {
    this.resetForm();
  }

  protected setStatus(
    quest: CampaignQuest,
    status: QuestStatus,
  ): void {
    if (
      this.saving()
      || quest.status === status
    ) {
      return;
    }

    this.saving.set(true);
    this.error.set(null);

    this.questApi
      .update(
        quest.id,
        { status },
      )
      .pipe(
        finalize(() => {
          this.saving.set(false);
        }),
      )
      .subscribe({
        next: (updatedQuest) => {
          this.replaceQuest(updatedQuest);
        },

        error: (error: any) => {
          console.error(
            'Impossible de modifier le statut.',
            error,
          );

          this.error.set(
            error?.error?.message ??
              'Le statut de la quête n’a pas pu être modifié.',
          );
        },
      });
  }

  protected moveQuest(
    quest: CampaignQuest,
    direction: -1 | 1,
  ): void {
    if (this.saving()) {
      return;
    }

    const quests =
      this.sortQuests(this.quests());

    const currentIndex =
      quests.findIndex(
        (candidate) =>
          candidate.id === quest.id,
      );

    const destinationIndex =
      currentIndex + direction;

    if (
      currentIndex < 0
      || destinationIndex < 0
      || destinationIndex >= quests.length
    ) {
      return;
    }

    const reordered = [...quests];

    const [movedQuest] =
      reordered.splice(currentIndex, 1);

    reordered.splice(
      destinationIndex,
      0,
      movedQuest,
    );

    this.saving.set(true);
    this.error.set(null);

    /*
     * Toute la liste est renumérotée par pas de 10.
     * Cela évite les doublons d’ordre après plusieurs
     * déplacements successifs.
     */
    forkJoin(
      reordered.map(
        (candidate, index) =>
          this.questApi.update(
            candidate.id,
            {
              displayOrder:
                (index + 1) * 10,
            },
          ),
      ),
    )
      .pipe(
        finalize(() => {
          this.saving.set(false);
        }),
      )
      .subscribe({
        next: (updatedQuests) => {
          this.quests.set(
            this.sortQuests(
              updatedQuests,
            ),
          );
        },

        error: (error: any) => {
          console.error(
            'Impossible de réordonner les quêtes.',
            error,
          );

          this.error.set(
            error?.error?.message ??
              'L’ordre des quêtes n’a pas pu être enregistré.',
          );

          this.loadQuests();
        },
      });
  }

  protected isFirst(
    quest: CampaignQuest,
  ): boolean {
    return this.sortQuests(
      this.quests(),
    )[0]?.id === quest.id;
  }

  protected isLast(
    quest: CampaignQuest,
  ): boolean {
    const quests =
      this.sortQuests(this.quests());

    return quests.at(-1)?.id === quest.id;
  }

  protected statusLabel(
    status: QuestStatus,
  ): string {
    switch (status) {
      case 'active':
        return 'Active';

      case 'completed':
        return 'Terminée';

      case 'hidden':
        return 'Masquée';
    }
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
            this.sortQuests(quests),
          );
        },

        error: (error: any) => {
          console.error(
            'Impossible de charger les quêtes.',
            error,
          );

          this.error.set(
            error?.error?.message ??
              'Les quêtes n’ont pas pu être chargées.',
          );
        },
      });
  }

  private replaceQuest(
    updatedQuest: CampaignQuest,
  ): void {
    this.quests.update(
      (quests) =>
        this.sortQuests(
          quests.map(
            (quest) =>
              quest.id === updatedQuest.id
                ? updatedQuest
                : quest,
          ),
        ),
    );
  }

  private sortQuests(
    quests: CampaignQuest[],
  ): CampaignQuest[] {
    return [...quests].sort(
      (first, second) =>
        first.displayOrder -
        second.displayOrder,
    );
  }

  private resetForm(): void {
    this.editingQuestId.set(null);

    this.questForm.reset({
      title: '',
      category: '',
      objective: '',
    });
  }
}

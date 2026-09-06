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
  CampaignTip,
  TipApiService,
  TipCategory,
} from '@core/services/tip-api.service';

@Component({
  selector: 'app-tip-manager',
  imports: [
    ReactiveFormsModule,
  ],
  templateUrl: './tip-manager.html',
  styleUrl: './tip-manager.scss',
})
export class TipManager implements OnInit {
  readonly campaignId =
    input.required<number>();

  private readonly formBuilder =
    inject(FormBuilder);

  private readonly tipApi =
    inject(TipApiService);

  protected readonly tips =
    signal<CampaignTip[]>([]);

  protected readonly loading =
    signal(true);

  protected readonly saving =
    signal(false);

  protected readonly error =
    signal<string | null>(null);

  protected readonly editingTipId =
    signal<number | null>(null);

  protected readonly tipForm =
    this.formBuilder.nonNullable.group({
      label: [
        '',
        [
          Validators.required,
          Validators.maxLength(100),
        ],
      ],

      text: [
        '',
        Validators.required,
      ],

      category:
        this.formBuilder.nonNullable.control<
          TipCategory
        >('rule'),
    });

  ngOnInit(): void {
    this.loadTips();
  }

  protected submitTip(): void {
    if (
      this.tipForm.invalid
      || this.saving()
    ) {
      this.tipForm.markAllAsTouched();

      return;
    }

    const payload =
      this.tipForm.getRawValue();

    const editingId =
      this.editingTipId();

    const request$ = editingId === null
      ? this.tipApi.create(
          this.campaignId(),
          payload,
        )
      : this.tipApi.update(
          editingId,
          payload,
        );

    this.saving.set(true);
    this.error.set(null);

    request$
      .pipe(
        finalize(() => {
          this.saving.set(false);
        }),
      )
      .subscribe({
        next: (tip) => {
          if (editingId === null) {
            this.tips.update(
              (tips) =>
                this.sortTips([
                  ...tips,
                  tip,
                ]),
            );
          } else {
            this.replaceTip(tip);
          }

          this.resetForm();
        },

        error: (error: any) => {
          console.error(
            'Impossible d’enregistrer le tip.',
            error,
          );

          this.error.set(
            error?.error?.message ??
              'Le tip n’a pas pu être enregistré.',
          );
        },
      });
  }

  protected editTip(
    tip: CampaignTip,
  ): void {
    this.editingTipId.set(tip.id);

    this.tipForm.setValue({
      label: tip.label,
      text: tip.text,
      category: tip.category,
    });
  }

  protected cancelEdition(): void {
    this.resetForm();
  }

  protected toggleVisibility(
    tip: CampaignTip,
  ): void {
    if (this.saving()) {
      return;
    }

    this.saving.set(true);
    this.error.set(null);

    this.tipApi
      .update(
        tip.id,
        {
          status:
            tip.status === 'visible'
              ? 'hidden'
              : 'visible',
        },
      )
      .pipe(
        finalize(() => {
          this.saving.set(false);
        }),
      )
      .subscribe({
        next: (updatedTip) => {
          this.replaceTip(updatedTip);
        },

        error: (error: any) => {
          this.error.set(
            error?.error?.message ??
              'La visibilité n’a pas pu être modifiée.',
          );
        },
      });
  }

  protected moveTip(
    tip: CampaignTip,
    direction: -1 | 1,
  ): void {
    if (this.saving()) {
      return;
    }

    const tips =
      this.sortTips(this.tips());

    const currentIndex =
      tips.findIndex(
        (candidate) =>
          candidate.id === tip.id,
      );

    const destinationIndex =
      currentIndex + direction;

    if (
      currentIndex < 0
      || destinationIndex < 0
      || destinationIndex >= tips.length
    ) {
      return;
    }

    const reordered = [...tips];

    const [movedTip] =
      reordered.splice(currentIndex, 1);

    reordered.splice(
      destinationIndex,
      0,
      movedTip,
    );

    this.saving.set(true);
    this.error.set(null);

    forkJoin(
      reordered.map(
        (candidate, index) =>
          this.tipApi.update(
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
        next: (updatedTips) => {
          this.tips.set(
            this.sortTips(updatedTips),
          );
        },

        error: (error: any) => {
          this.error.set(
            error?.error?.message ??
              'L’ordre des tips n’a pas pu être enregistré.',
          );

          this.loadTips();
        },
      });
  }

  protected isFirst(
    tip: CampaignTip,
  ): boolean {
    return this.sortTips(
      this.tips(),
    )[0]?.id === tip.id;
  }

  protected isLast(
    tip: CampaignTip,
  ): boolean {
    return this.sortTips(
      this.tips(),
    ).at(-1)?.id === tip.id;
  }

  protected categoryLabel(
    category: TipCategory,
  ): string {
    switch (category) {
      case 'rule':
        return 'Règle';

      case 'advice':
        return 'Conseil';

      case 'lore':
        return 'Univers';
    }
  }

  private loadTips(): void {
    this.loading.set(true);
    this.error.set(null);

    this.tipApi
      .list(this.campaignId())
      .pipe(
        finalize(() => {
          this.loading.set(false);
        }),
      )
      .subscribe({
        next: (tips) => {
          this.tips.set(
            this.sortTips(tips),
          );
        },

        error: (error: any) => {
          this.error.set(
            error?.error?.message ??
              'Les tips sont indisponibles.',
          );
        },
      });
  }

  private replaceTip(
    updatedTip: CampaignTip,
  ): void {
    this.tips.update(
      (tips) =>
        this.sortTips(
          tips.map(
            (tip) =>
              tip.id === updatedTip.id
                ? updatedTip
                : tip,
          ),
        ),
    );
  }

  private sortTips(
    tips: CampaignTip[],
  ): CampaignTip[] {
    return [...tips].sort(
      (first, second) =>
        first.displayOrder -
        second.displayOrder,
    );
  }

  private resetForm(): void {
    this.editingTipId.set(null);

    this.tipForm.reset({
      label: '',
      text: '',
      category: 'rule',
    });
  }
}

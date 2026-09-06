import {
  Component,
  ElementRef,
  OnDestroy,
  OnInit,
  ViewChild,
  inject,
  input,
  signal,
  output,
} from '@angular/core';
import {
  FormBuilder,
  FormControl,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import {
  catchError,
  finalize,
  map,
  Observable,
  of,
  switchMap,
  throwError,
} from 'rxjs';

import {
  CampaignFigure,
  CampaignFigureApiService,
  FigureCharacterType,
  FigureEncounterStatus,
  FigureLifeStatus,
  FigurePublicationStatus,
} from '@core/services/campaign-figure-api.service';
import {
  FigurePanelMode,
} from '@core/models/live-session-state.model';
import { MediaApiService } from '@core/services/media-api.service';

@Component({
  selector: 'app-campaign-figure-manager',
  imports: [
    ReactiveFormsModule,
  ],
  templateUrl:
    './campaign-figure-manager.html',
  styleUrl:
    './campaign-figure-manager.scss',
})
export class CampaignFigureManager
  implements OnInit, OnDestroy
{
  readonly campaignId =
    input.required<number>();

  readonly displayMode =
    input<FigurePanelMode>('memorial');

  readonly displayModeChanged =
    output<FigurePanelMode>();

  @ViewChild('portraitInput')
  private portraitInput?:
    ElementRef<HTMLInputElement>;

  private readonly formBuilder =
    inject(FormBuilder);

  private readonly figureApi =
    inject(CampaignFigureApiService);

  private readonly mediaApi =
    inject(MediaApiService);

  protected readonly figures =
    signal<CampaignFigure[]>([]);

  protected readonly loading =
    signal(true);

  protected readonly saving =
    signal(false);

  protected readonly error =
    signal<string | null>(null);

  protected readonly editingFigureId =
    signal<number | null>(null);

  protected readonly selectedPortrait =
    signal<File | null>(null);

  protected readonly portraitPreviewUrl =
    signal<string | null>(null);

  protected readonly figureForm =
    this.formBuilder.group({
      name:
        this.formBuilder.nonNullable.control(
          '',
          [
            Validators.required,
            Validators.maxLength(120),
          ],
        ),

      characterType:
        this.formBuilder.nonNullable.control<
          FigureCharacterType
        >('npc'),

      encounterStatus:
        this.formBuilder.nonNullable.control<
          FigureEncounterStatus
        >('unmet'),

      lifeStatus:
        this.formBuilder.nonNullable.control<
          FigureLifeStatus
        >('alive'),

      description:
        this.formBuilder.nonNullable.control(
          '',
          [
            Validators.required,
            Validators.maxLength(255),
          ],
        ),

      deathLabel:
        this.formBuilder.nonNullable.control(
          '',
          Validators.maxLength(80),
        ),

      portraitId:
        new FormControl<number | null>(
          null,
        ),
    });

  ngOnInit(): void {
    this.loadData();
  }

  ngOnDestroy(): void {
    this.releasePortraitPreview();
  }

  protected selectDisplayMode(
    mode: FigurePanelMode,
  ): void {
    if (mode === this.displayMode()) {
      return;
    }

    this.displayModeChanged.emit(mode);
  }

  protected submitFigure(): void {
    if (
      this.figureForm.invalid
      || this.saving()
    ) {
      this.figureForm.markAllAsTouched();

      return;
    }

    const values =
      this.figureForm.getRawValue();

    const editingId =
      this.editingFigureId();

    this.saving.set(true);
    this.error.set(null);

    this.uploadPortraitIfNecessary(
      values.name,
    )
      .pipe(
        switchMap((uploadedPortraitId) => {
          const payload = {
            name: values.name,
            characterType:
              values.characterType,
            encounterStatus:
              values.encounterStatus,
            lifeStatus:
              values.lifeStatus,
            description:
              values.description,
            deathLabel:
              values.deathLabel.trim() || null,
            portraitId:
              uploadedPortraitId
              ?? values.portraitId,
          };

          const request$ = editingId === null
            ? this.figureApi.create(
                this.campaignId(),
                payload,
              )
            : this.figureApi.update(
                editingId,
                payload,
              );

          return request$.pipe(
            catchError((error) =>
              this.rollbackPortrait(
                uploadedPortraitId,
                error,
              ),
            ),
          );
        }),
        finalize(() => {
          this.saving.set(false);
        }),
      )
      .subscribe({
        next: (figure) => {
          if (editingId === null) {
            this.figures.update(
              (figures) => [
                ...figures,
                figure,
              ],
            );
          } else {
            this.replaceFigure(figure);
          }

          this.resetForm();
        },

        error: (error: any) => {
          console.error(
            'Impossible d’enregistrer la figure.',
            error,
          );

          this.error.set(
            error?.error?.message ??
              'La figure n’a pas pu être enregistrée.',
          );
        },
      });
  }

  protected onPortraitSelected(
    event: Event,
  ): void {
    const inputElement =
      event.target as HTMLInputElement;

    const file =
      inputElement.files?.[0] ?? null;

    this.releasePortraitPreview();
    this.selectedPortrait.set(file);

    if (file) {
      this.portraitPreviewUrl.set(
        URL.createObjectURL(file),
      );
    }
  }

  protected removeSelectedPortrait(): void {
    this.releasePortraitPreview();
    this.selectedPortrait.set(null);

    if (this.portraitInput) {
      this.portraitInput.nativeElement.value = '';
    }
  }

  protected editFigure(
    figure: CampaignFigure,
  ): void {
    this.removeSelectedPortrait();

    this.editingFigureId.set(
      figure.id,
    );

    this.figureForm.setValue({
      name: figure.name,
      characterType:
        figure.characterType,
      encounterStatus:
        figure.encounterStatus,
      lifeStatus:
        figure.lifeStatus,
      description:
        figure.description,
      deathLabel:
        figure.deathLabel ?? '',
      portraitId:
        figure.portrait?.id ?? null,
    });
  }

  protected cancelEdition(): void {
    this.resetForm();
  }

  protected markEncountered(
    figure: CampaignFigure,
  ): void {
    this.updateFigure(
      figure,
      {
        encounterStatus:
          figure.encounterStatus === 'met'
            ? 'unmet'
            : 'met',
      },
    );
  }

  protected toggleLifeStatus(
    figure: CampaignFigure,
  ): void {
    this.updateFigure(
      figure,
      {
        lifeStatus:
          figure.lifeStatus === 'alive'
            ? 'dead'
            : 'alive',
      },
    );
  }

  protected toggleVisibility(
    figure: CampaignFigure,
  ): void {
    this.updateFigure(
      figure,
      {
        publicationStatus:
          figure.publicationStatus ===
          'visible'
            ? 'hidden'
            : 'visible',
      },
    );
  }

  protected characterTypeLabel(
    type: FigureCharacterType,
  ): string {
    return type === 'pc'
      ? 'PJ'
      : 'PNJ';
  }

  protected encounterLabel(
    status: FigureEncounterStatus,
  ): string {
    return status === 'met'
      ? 'Rencontré'
      : 'Non rencontré';
  }

  protected lifeLabel(
    status: FigureLifeStatus,
  ): string {
    return status === 'alive'
      ? 'Vivant'
      : 'Mort';
  }

  protected visibilityLabel(
    status: FigurePublicationStatus,
  ): string {
    return status === 'visible'
      ? 'Visible'
      : 'Masqué';
  }

  private loadData(): void {
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
        },

        error: (error: any) => {
          console.error(
            'Impossible de charger les figures.',
            error,
          );

          this.error.set(
            error?.error?.message ??
              'Les figures de la campagne sont indisponibles.',
          );
        },
      });
  }

  private updateFigure(
    figure: CampaignFigure,
    payload: {
      encounterStatus?:
        FigureEncounterStatus;
      lifeStatus?:
        FigureLifeStatus;
      publicationStatus?:
        FigurePublicationStatus;
    },
  ): void {
    if (this.saving()) {
      return;
    }

    this.saving.set(true);
    this.error.set(null);

    this.figureApi
      .update(
        figure.id,
        payload,
      )
      .pipe(
        finalize(() => {
          this.saving.set(false);
        }),
      )
      .subscribe({
        next: (updatedFigure) => {
          this.replaceFigure(
            updatedFigure,
          );
        },

        error: (error: any) => {
          console.error(
            'Impossible de modifier la figure.',
            error,
          );

          this.error.set(
            error?.error?.message ??
              'La figure n’a pas pu être modifiée.',
          );
        },
      });
  }

  private replaceFigure(
    updatedFigure: CampaignFigure,
  ): void {
    this.figures.update(
      (figures) =>
        figures.map(
          (figure) =>
            figure.id ===
            updatedFigure.id
              ? updatedFigure
              : figure,
        ),
    );
  }

  private resetForm(): void {
    this.removeSelectedPortrait();
    this.editingFigureId.set(null);

    this.figureForm.reset({
      name: '',
      characterType: 'npc',
      encounterStatus: 'unmet',
      lifeStatus: 'alive',
      description: '',
      deathLabel: '',
      portraitId: null,
    });
  }

  private uploadPortraitIfNecessary(
    figureName: string,
  ): Observable<number | null> {
    const portrait = this.selectedPortrait();

    if (!portrait) {
      return of(null);
    }

    return this.mediaApi
      .upload(
        this.campaignId(),
        portrait,
        `Portrait de ${figureName.trim()}`,
        'portrait',
      )
      .pipe(
        map((media) => media.id),
      );
  }

  private rollbackPortrait(
    portraitId: number | null,
    originalError: unknown,
  ): Observable<never> {
    if (portraitId === null) {
      return throwError(
        () => originalError,
      );
    }

    return this.mediaApi
      .remove(
        this.campaignId(),
        portraitId,
      )
      .pipe(
        catchError((rollbackError) => {
          console.error(
            'Le portrait orphelin n’a pas pu être supprimé.',
            rollbackError,
          );

          return of(undefined);
        }),
        switchMap(() =>
          throwError(
            () => originalError,
          ),
        ),
      );
  }

  private releasePortraitPreview(): void {
    const previewUrl =
      this.portraitPreviewUrl();

    if (previewUrl) {
      URL.revokeObjectURL(previewUrl);
      this.portraitPreviewUrl.set(null);
    }
  }
}

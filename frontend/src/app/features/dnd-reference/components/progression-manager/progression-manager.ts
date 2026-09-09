import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  DndReferenceApiService,
  ProgressionReference,
  ProgressionStageReference,
  SaveProgressionPayload,
  SaveProgressionStagePayload,
} from '../../../../core/services/dnd-reference-api.service';

interface ProgressionForm {
  slug: string;
  name: string;
  description: string;
  minimumValue: number;
  maximumValue: number | null;
  accentColor: string;
  gainLabel: string;
  spendLabel: string;
  custom: boolean;
  bulkAdjustmentEnabled: boolean,
}

interface StageForm {
  id: number | null;
  label: string;
  minimumValue: number;
  maximumValue: number | null;
  iconUrl: string;
  displayOrder: number;
}

@Component({
  selector: 'app-progression-manager',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './progression-manager.html',
  styleUrl: './progression-manager.scss',
})
export class ProgressionManager {
  private readonly api = inject(DndReferenceApiService);

  readonly progressions = signal<ProgressionReference[]>([]);
  readonly selectedProgressionId = signal<number | null>(null);
  readonly search = signal('');
  readonly loading = signal(false);
  readonly submitting = signal(false);
  readonly deleting = signal(false);
  readonly stageSubmitting = signal(false);
  readonly error = signal<string | null>(null);
  readonly success = signal<string | null>(null);

  form: ProgressionForm = this.emptyForm();
  stageForm: StageForm = this.emptyStageForm();

  readonly selectedProgression = computed(() =>
    this.progressions().find(
      progression =>
        progression.id === this.selectedProgressionId(),
    ) ?? null,
  );

  readonly filteredProgressions = computed(() => {
    const search = this.search().trim().toLowerCase();

    if (!search) {
      return this.progressions();
    }

    return this.progressions().filter(progression =>
      progression.name.toLowerCase().includes(search)
      || progression.slug.toLowerCase().includes(search),
    );
  });

  constructor() {
    this.loadData();
  }

  loadData(): void {
    this.loading.set(true);
    this.error.set(null);

    this.api.getProgressions().subscribe({
      next: response => {
        this.progressions.set(response.progressions);
        this.loading.set(false);
      },
      error: error => {
        this.error.set(
          error?.error?.message
          ?? 'Impossible de charger les progressions.',
        );
        this.loading.set(false);
      },
    });
  }

  selectProgression(
    progression: ProgressionReference,
  ): void {
    this.selectedProgressionId.set(progression.id);

    this.form = {
      slug: progression.slug,
      name: progression.name,
      description: progression.description ?? '',
      minimumValue: progression.minimumValue,
      maximumValue: progression.maximumValue,
      accentColor: progression.accentColor ?? '',
      gainLabel: progression.gainLabel ?? '',
      spendLabel: progression.spendLabel ?? '',
      custom: progression.custom,
      bulkAdjustmentEnabled: progression.bulkAdjustmentEnabled,
    };

    this.stageForm = this.emptyStageForm();
    this.clearMessages();
  }

  startNew(): void {
    this.selectedProgressionId.set(null);
    this.form = this.emptyForm();
    this.stageForm = this.emptyStageForm();
    this.clearMessages();
  }

  save(): void {
    const payload = this.progressionPayload();

    if (!payload.name.trim() || !payload.slug.trim()) {
      this.error.set(
        'Le nom et le slug sont obligatoires.',
      );
      return;
    }

    this.submitting.set(true);
    this.clearMessages();

    const selectedId = this.selectedProgressionId();

    const request = selectedId === null
      ? this.api.createProgression(payload)
      : this.api.updateProgression(
          selectedId,
          payload,
        );

    request.subscribe({
      next: progression => {
        this.replaceProgression(progression);
        this.selectProgression(progression);
        this.success.set(
          selectedId === null
            ? 'Progression créée.'
            : 'Progression mise à jour.',
        );
        this.submitting.set(false);
      },
      error: error => {
        this.error.set(
          error?.error?.message
          ?? 'Impossible d’enregistrer la progression.',
        );
        this.submitting.set(false);
      },
    });
  }

  deleteProgression(): void {
    const progression = this.selectedProgression();

    if (!progression) {
      return;
    }

    if (!confirm(
      `Supprimer la progression "${progression.name}" ?`,
    )) {
      return;
    }

    this.deleting.set(true);
    this.clearMessages();

    this.api.deleteProgression(
      progression.id,
    ).subscribe({
      next: () => {
        this.progressions.update(
          progressions =>
            progressions.filter(
              item => item.id !== progression.id,
            ),
        );

        this.startNew();
        this.success.set('Progression supprimée.');
        this.deleting.set(false);
      },
      error: error => {
        this.error.set(
          error?.error?.message
          ?? 'Impossible de supprimer la progression.',
        );
        this.deleting.set(false);
      },
    });
  }

  editStage(
    stage: ProgressionStageReference,
  ): void {
    this.stageForm = {
      id: stage.id,
      label: stage.label,
      minimumValue: stage.minimumValue,
      maximumValue: stage.maximumValue,
      iconUrl: stage.iconUrl ?? '',
      displayOrder: stage.displayOrder,
    };

    this.clearMessages();
  }

  startNewStage(): void {
    const progression = this.selectedProgression();

    this.stageForm = {
      ...this.emptyStageForm(),
      minimumValue:
        progression?.minimumValue ?? 0,
      displayOrder:
        progression?.stages.length ?? 0,
    };

    this.clearMessages();
  }

  saveStage(): void {
    const progression = this.selectedProgression();

    if (!progression) {
      this.error.set(
        'Enregistre d’abord la progression.',
      );
      return;
    }

    const payload = this.stagePayload();

    if (!payload.label.trim()) {
      this.error.set(
        'Le nom du palier est obligatoire.',
      );
      return;
    }

    this.stageSubmitting.set(true);
    this.clearMessages();

    const request = this.stageForm.id === null
      ? this.api.createProgressionStage(
          progression.id,
          payload,
        )
      : this.api.updateProgressionStage(
          progression.id,
          this.stageForm.id,
          payload,
        );

    request.subscribe({
      next: updatedProgression => {
        this.replaceProgression(updatedProgression);
        this.selectProgression(updatedProgression);
        this.success.set(
          this.stageForm.id === null
            ? 'Palier ajouté.'
            : 'Palier mis à jour.',
        );
        this.stageForm = this.emptyStageForm();
        this.stageSubmitting.set(false);
      },
      error: error => {
        this.error.set(
          error?.error?.message
          ?? 'Impossible d’enregistrer le palier.',
        );
        this.stageSubmitting.set(false);
      },
    });
  }

  deleteStage(
    stage: ProgressionStageReference,
  ): void {
    const progression = this.selectedProgression();

    if (!progression) {
      return;
    }

    if (!confirm(
      `Supprimer le palier "${stage.label}" ?`,
    )) {
      return;
    }

    this.clearMessages();

    this.api.deleteProgressionStage(
      progression.id,
      stage.id,
    ).subscribe({
      next: updatedProgression => {
        this.replaceProgression(updatedProgression);
        this.selectProgression(updatedProgression);
        this.success.set('Palier supprimé.');
      },
      error: error => {
        this.error.set(
          error?.error?.message
          ?? 'Impossible de supprimer le palier.',
        );
      },
    });
  }

  updateSearch(
    value: string,
  ): void {
    this.search.set(value);
  }

  generateSlug(): void {
    if (!this.form.name.trim()) {
      return;
    }

    this.form.slug = this.form.name
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }

  private replaceProgression(
    progression: ProgressionReference,
  ): void {
    this.progressions.update(progressions => {
      const exists = progressions.some(
        item => item.id === progression.id,
      );

      const next = exists
        ? progressions.map(item =>
            item.id === progression.id
              ? progression
              : item,
          )
        : [...progressions, progression];

      return next.sort(
        (a, b) =>
          a.name.localeCompare(
            b.name,
            'fr',
          ),
      );
    });
  }

  private progressionPayload(): SaveProgressionPayload {
    return {
      slug: this.form.slug.trim(),
      name: this.form.name.trim(),
      description: this.form.description.trim() || null,
      minimumValue: this.form.minimumValue,
      maximumValue: this.form.maximumValue,
      accentColor: this.form.accentColor.trim() || null,
      gainLabel: this.form.gainLabel.trim() || null,
      spendLabel: this.form.spendLabel.trim() || null,
      custom: this.form.custom,
      bulkAdjustmentEnabled: this.form.bulkAdjustmentEnabled,
    };
  }

  private stagePayload(): SaveProgressionStagePayload {
    return {
      label: this.stageForm.label.trim(),
      minimumValue:
        this.stageForm.minimumValue,
      maximumValue:
        this.stageForm.maximumValue,
      iconUrl:
        this.stageForm.iconUrl.trim() || null,
      displayOrder:
        this.stageForm.displayOrder,
    };
  }

  private emptyForm(): ProgressionForm {
    return {
      slug: '',
      name: '',
      description: '',
      minimumValue: 0,
      maximumValue: null,
      accentColor: '',
      gainLabel: '',
      spendLabel: '',
      custom: true,
      bulkAdjustmentEnabled: false,
    };
  }

  private emptyStageForm(): StageForm {
    return {
      id: null,
      label: '',
      minimumValue: 0,
      maximumValue: null,
      iconUrl: '',
      displayOrder: 0,
    };
  }

  private clearMessages(): void {
    this.error.set(null);
    this.success.set(null);
  }
}

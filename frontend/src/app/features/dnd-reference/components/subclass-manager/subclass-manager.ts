import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { forkJoin } from 'rxjs';
import {
  ClassReference,
  DndReferenceApiService,
  SaveSubclassPayload,
  SpellcastingProgression,
  SpellcastingProgressionChoice,
  SubclassReference,
} from '../../../../core/services/dnd-reference-api.service';

interface SubclassForm {
  classId: number | null;
  slug: string;
  name: string;
  description: string;
  spellcastingProgression: SpellcastingProgression | null;
  custom: boolean;
}

@Component({
  selector: 'app-subclass-manager',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './subclass-manager.html',
  styleUrl: './subclass-manager.scss',
})
export class SubclassManager {
  private readonly api = inject(DndReferenceApiService);

  protected readonly classes = signal<ClassReference[]>([]);
  protected readonly subclasses = signal<SubclassReference[]>([]);
  protected readonly spellcastingProgressions =
    signal<SpellcastingProgressionChoice[]>([]);

  protected readonly selectedSubclassId = signal<number | null>(null);
  protected readonly selectedClassFilter = signal<number | null>(null);
  protected readonly search = signal('');
  protected readonly loading = signal(true);
  protected readonly submitting = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);

  protected form: SubclassForm = this.emptyForm();

  protected readonly selectedSubclass = computed(() => {
    const selectedId = this.selectedSubclassId();
    return this.subclasses().find(subclass => subclass.id === selectedId) ?? null;
  });

  protected readonly filteredSubclasses = computed(() => {
    const search = this.search().trim().toLocaleLowerCase('fr');
    const classId = this.selectedClassFilter();

    return this.subclasses().filter(subclass => {
      const matchesClass = classId === null || subclass.classId === classId;
      const matchesSearch = !search ||
        `${subclass.name} ${subclass.slug} ${this.className(subclass.classId)}`
          .toLocaleLowerCase('fr')
          .includes(search);

      return matchesClass && matchesSearch;
    });
  });

  public constructor() {
    this.loadData();
  }

  protected selectSubclass(subclass: SubclassReference): void {
    this.selectedSubclassId.set(subclass.id);
    this.form = {
      classId: subclass.classId,
      slug: subclass.slug,
      name: subclass.name,
      description: subclass.description ?? '',
      spellcastingProgression: subclass.spellcastingProgression,
      custom: subclass.custom ?? false,
    };
    this.clearMessages();
  }

  protected startCreation(): void {
    this.selectedSubclassId.set(null);
    this.form = this.emptyForm();
    this.clearMessages();
  }

  protected save(): void {
    if (this.submitting()) {
      return;
    }

    if (
      this.form.classId === null ||
      !this.form.name.trim() ||
      !this.form.slug.trim()
    ) {
      this.error.set('La classe, le nom et le slug sont obligatoires.');
      return;
    }

    const payload: SaveSubclassPayload = {
      classId: Number(this.form.classId),
      slug: this.form.slug.trim(),
      name: this.form.name.trim(),
      description: this.form.description.trim() || null,
      spellcastingProgression: this.form.spellcastingProgression,
      custom: this.form.custom,
    };

    const selectedId = this.selectedSubclassId();
    const request = selectedId === null
      ? this.api.createSubclass(payload)
      : this.api.updateSubclass(selectedId, payload);

    this.submitting.set(true);
    this.clearMessages();

    request.subscribe({
      next: response => {
        this.replaceSubclass(response.subclass);
        this.selectSubclass(response.subclass);
        this.success.set(
          selectedId === null
            ? `${response.subclass.name} a bien été créée.`
            : `${response.subclass.name} a bien été mise à jour.`,
        );
        this.submitting.set(false);
      },
      error: error => {
        this.error.set(
          error.error?.error ??
          error.error?.message ??
          'Impossible d’enregistrer cette sous-classe.',
        );
        this.submitting.set(false);
      },
    });
  }

  protected generateSlug(): void {
    this.form.slug = this.slugify(this.form.name);
  }

  protected updateSearch(value: string): void {
    this.search.set(value);
  }

  protected updateClassFilter(value: number | null): void {
    this.selectedClassFilter.set(value);
  }

  protected className(classId: number): string {
    return this.classes().find(characterClass => characterClass.id === classId)?.name ??
      'Classe inconnue';
  }

  protected progressionLabel(
    progression: SpellcastingProgression | null,
  ): string {
    if (progression === null) {
      return 'Aucune progression ajoutée';
    }

    return this.spellcastingProgressions()
      .find(choice => choice.value === progression)?.label ?? progression;
  }

  protected trackSubclass(
    _: number,
    subclass: SubclassReference,
  ): number {
    return subclass.id;
  }

  private loadData(): void {
    this.loading.set(true);
    this.error.set(null);

    forkJoin({
      classes: this.api.getClasses(),
      subclasses: this.api.getSubclasses(),
    }).subscribe({
      next: result => {
        this.classes.set(
          [...result.classes.classes].sort((first, second) =>
            first.name.localeCompare(second.name, 'fr'),
          ),
        );
        this.subclasses.set(this.sortSubclasses(result.subclasses.subclasses));
        this.spellcastingProgressions.set(
          result.subclasses.spellcastingProgressions,
        );
        this.loading.set(false);
      },
      error: error => {
        this.error.set(
          error.error?.error ??
          error.error?.message ??
          'Impossible de charger les sous-classes.',
        );
        this.loading.set(false);
      },
    });
  }

  private replaceSubclass(savedSubclass: SubclassReference): void {
    const exists = this.subclasses().some(
      subclass => subclass.id === savedSubclass.id,
    );

    const updatedSubclasses = exists
      ? this.subclasses().map(subclass =>
          subclass.id === savedSubclass.id ? savedSubclass : subclass,
        )
      : [...this.subclasses(), savedSubclass];

    this.subclasses.set(this.sortSubclasses(updatedSubclasses));
  }

  private sortSubclasses(
    subclasses: SubclassReference[],
  ): SubclassReference[] {
    return [...subclasses].sort((first, second) => {
      const classComparison = this.className(first.classId).localeCompare(
        this.className(second.classId),
        'fr',
      );

      return classComparison ||
        first.name.localeCompare(second.name, 'fr');
    });
  }

  private emptyForm(): SubclassForm {
    return {
      classId: null,
      slug: '',
      name: '',
      description: '',
      spellcastingProgression: null,
      custom: true,
    };
  }

  private slugify(value: string): string {
    return value
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLocaleLowerCase('fr')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }

  private clearMessages(): void {
    this.error.set(null);
    this.success.set(null);
  }
}

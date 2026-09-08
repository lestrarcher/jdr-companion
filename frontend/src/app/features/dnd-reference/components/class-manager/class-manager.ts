import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  ClassReference,
  DndReferenceApiService,
  SaveClassPayload,
  SpellcastingProgression,
  SpellcastingProgressionChoice,
} from '../../../../core/services/dnd-reference-api.service';

interface ClassForm {
  slug: string;
  name: string;
  hitDie: number;
  subclassSelectionLevel: number;
  spellcastingProgression: SpellcastingProgression;
  description: string;
  custom: boolean;
}

@Component({
  selector: 'app-class-manager',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './class-manager.html',
  styleUrl: './class-manager.scss',
})
export class ClassManager {
  private readonly api = inject(DndReferenceApiService);

  protected readonly classes = signal<ClassReference[]>([]);
  protected readonly hitDice = signal<number[]>([6, 8, 10, 12]);
  protected readonly spellcastingProgressions =
    signal<SpellcastingProgressionChoice[]>([]);

  protected readonly selectedClassId = signal<number | null>(null);
  protected readonly loading = signal(true);
  protected readonly submitting = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);
  protected readonly search = signal('');

  protected form: ClassForm = this.emptyForm();

  protected readonly selectedClass = computed(() => {
    const selectedId = this.selectedClassId();
    return this.classes().find(characterClass => characterClass.id === selectedId) ?? null;
  });

  protected readonly filteredClasses = computed(() => {
    const search = this.search().trim().toLocaleLowerCase('fr');

    if (!search) {
      return this.classes();
    }

    return this.classes().filter(characterClass =>
      `${characterClass.name} ${characterClass.slug}`
        .toLocaleLowerCase('fr')
        .includes(search),
    );
  });

  public constructor() {
    this.loadClasses();
  }

  protected selectClass(characterClass: ClassReference): void {
    this.selectedClassId.set(characterClass.id);
    this.form = {
      slug: characterClass.slug,
      name: characterClass.name,
      hitDie: characterClass.hitDie,
      subclassSelectionLevel: characterClass.subclassSelectionLevel,
      spellcastingProgression: characterClass.spellcastingProgression,
      description: characterClass.description ?? '',
      custom: characterClass.custom ?? false,
    };
    this.clearMessages();
  }

  protected startCreation(): void {
    this.selectedClassId.set(null);
    this.form = this.emptyForm();
    this.clearMessages();
  }

  protected save(): void {
    if (this.submitting()) {
      return;
    }

    const payload = this.buildPayload();

    if (!payload.name || !payload.slug) {
      this.error.set('Le nom et le slug sont obligatoires.');
      return;
    }

    this.submitting.set(true);
    this.clearMessages();

    const selectedId = this.selectedClassId();
    const request = selectedId === null
      ? this.api.createClass(payload)
      : this.api.updateClass(selectedId, payload);

    request.subscribe({
      next: savedClass => {
        this.replaceClass(savedClass);
        this.selectClass(savedClass);
        this.success.set(
          selectedId === null
            ? `${savedClass.name} a bien été créée.`
            : `${savedClass.name} a bien été mise à jour.`,
        );
        this.submitting.set(false);
      },
      error: error => {
        this.error.set(
          error.error?.error ??
          error.error?.message ??
          'Impossible d’enregistrer cette classe.',
        );
        this.submitting.set(false);
      },
    });
  }

  protected updateSearch(value: string): void {
    this.search.set(value);
  }

  protected trackClass(_: number, characterClass: ClassReference): number {
    return characterClass.id;
  }

  protected progressionLabel(value: SpellcastingProgression): string {
    return this.spellcastingProgressions()
      .find(progression => progression.value === value)?.label ?? value;
  }

  private loadClasses(): void {
    this.loading.set(true);
    this.error.set(null);

    this.api.getClasses().subscribe({
      next: result => {
        this.classes.set(
          [...result.classes].sort((first, second) =>
            first.name.localeCompare(second.name, 'fr'),
          ),
        );
        this.hitDice.set(result.hitDice);
        this.spellcastingProgressions.set(result.spellcastingProgressions);
        this.loading.set(false);
      },
      error: error => {
        this.error.set(
          error.error?.error ??
          error.error?.message ??
          'Impossible de charger les classes.',
        );
        this.loading.set(false);
      },
    });
  }

  private replaceClass(savedClass: ClassReference): void {
    const classes = this.classes();
    const index = classes.findIndex(characterClass => characterClass.id === savedClass.id);

    const updatedClasses = index === -1
      ? [...classes, savedClass]
      : classes.map(characterClass =>
          characterClass.id === savedClass.id ? savedClass : characterClass,
        );

    this.classes.set(
      updatedClasses.sort((first, second) =>
        first.name.localeCompare(second.name, 'fr'),
      ),
    );
  }

  private buildPayload(): SaveClassPayload {
    return {
      slug: this.form.slug.trim(),
      name: this.form.name.trim(),
      hitDie: Number(this.form.hitDie),
      subclassSelectionLevel: Number(this.form.subclassSelectionLevel),
      spellcastingProgression: this.form.spellcastingProgression,
      description: this.form.description.trim() || null,
      custom: this.form.custom,
    };
  }

  private emptyForm(): ClassForm {
    return {
      slug: '',
      name: '',
      hitDie: 8,
      subclassSelectionLevel: 3,
      spellcastingProgression: 'none',
      description: '',
      custom: true,
    };
  }

  private clearMessages(): void {
    this.error.set(null);
    this.success.set(null);
  }
}

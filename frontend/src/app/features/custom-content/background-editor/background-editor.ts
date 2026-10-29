import { REFERENCE_READ_ONLY } from '@core/services/reference-editor-context';
import { afterNextRender, Component, DestroyRef, ElementRef, inject, Injector, output, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, forkJoin } from 'rxjs';
import { BackgroundOptions, BackgroundPayload, BackgroundRule, CustomBackground } from '@core/models/custom-background.model';
import { CustomContentApiService } from '@core/services/custom-content-api.service';

@Component({
  selector: 'app-background-editor', imports: [FormsModule],
  templateUrl: './background-editor.html',
  styleUrls: ['../resource-editor/resource-editor.scss', './background-editor.scss'],
})
export class BackgroundEditor {
  private readonly api = inject(CustomContentApiService);
  readonly official = this.api.scope === 'official';
  readonly contextReadOnly = inject(REFERENCE_READ_ONLY);
  readOnly(): boolean { return this.contextReadOnly || this.selected() !== null && (this.selected()!.origin === 'OFFICIAL') !== this.official; }
  private readonly destroyRef = inject(DestroyRef);
  private readonly injector = inject(Injector);
  private readonly nameInput = viewChild<ElementRef<HTMLInputElement>>('nameInput');
  private readonly createButton = viewChild<ElementRef<HTMLButtonElement>>('createButton');
  private trigger: HTMLElement | null = null;
  private original = '';
  private slugEdited = false;
  readonly busyChange = output<boolean>();
  readonly busy = signal(false);
  readonly loading = signal(true);
  readonly error = signal('');
  readonly feedback = signal('');
  readonly items = signal<CustomBackground[]>([]);
  search = '';
  filteredItems() { return this.items().filter(item => item.name.toLocaleLowerCase('fr').includes(this.search.toLocaleLowerCase('fr'))); }
  readonly options = signal<BackgroundOptions>({ proficiencies: [], languages: [] });
  readonly opened = signal(false);
  readonly selected = signal<CustomBackground | null>(null);
  draft: BackgroundPayload = this.blank();
  readonly narratives = [
    { key: 'personalityTraits', label: 'Traits de personnalité' }, { key: 'ideals', label: 'Idéaux' },
    { key: 'bonds', label: 'Liens' }, { key: 'flaws', label: 'Défauts' },
  ] as const;
  readonly groups = [
    { key: 'proficiencyRules', label: 'Compétences et outils' },
    { key: 'languageRules', label: 'Langues' },
  ] as const;

  constructor() { this.load(); }
  private blank(): BackgroundPayload {
    return { name: '', slug: '', description: null, equipment: null, featureName: null, featureDescription: null,
      personalityTraits: [], ideals: [], bonds: [], flaws: [], proficiencyRules: [], languageRules: [] };
  }
  load(): void {
    this.loading.set(true); this.error.set('');
    forkJoin({ items: this.api.backgrounds(), options: this.api.backgroundOptions() })
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.loading.set(false))).subscribe({
        next: result => { this.items.set(result.items); this.options.set(result.options); },
        error: () => this.error.set('Impossible de charger les historiques. Réessaie.'),
      });
  }
  canDiscard(): boolean {
    return !this.busy() && (!this.opened() || this.readOnly() || JSON.stringify(this.draft) === this.original || window.confirm('Abandonner les modifications non enregistrées ?'));
  }
  open(item: CustomBackground | null, event: Event): void {
    if ((!item && this.contextReadOnly) || !this.canDiscard()) return;
    this.trigger = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    this.selected.set(item);
    const { id, origin, mechanicsLocked, ...payload } = item ?? { ...this.blank(), id: null, origin: 'CUSTOM', mechanicsLocked: false };
    this.draft = structuredClone(payload);
    this.original = JSON.stringify(this.draft); this.slugEdited = item !== null;
    this.opened.set(true); this.error.set(''); this.feedback.set('');
    afterNextRender(() => this.nameInput()?.nativeElement.focus(), { injector: this.injector });
  }
  close(): void {
    if (!this.canDiscard()) return;
    this.opened.set(false);
    afterNextRender(() => (this.trigger?.isConnected ? this.trigger : this.createButton()?.nativeElement)?.focus(), { injector: this.injector });
  }
  nameChanged(value: string): void {
    this.draft.name = value;
    if (!this.slugEdited) this.draft.slug = value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 80).replace(/-$/, '');
  }
  slugChanged(value: string): void { this.slugEdited = true; this.draft.slug = value; }
  addRule(key: 'proficiencyRules' | 'languageRules'): void {
    this.draft[key].push({ id: null, label: '', choiceCount: 0, optionIds: [] });
  }
  ruleOptions(key: 'proficiencyRules' | 'languageRules', rule: BackgroundRule, search = '') {
    const matches = (option: { id: number; name: string }) => rule.optionIds.includes(option.id) || option.name.toLocaleLowerCase('fr').includes(search.toLocaleLowerCase('fr'));
    if (key === 'languageRules') return this.options().languages.filter(matches);
    const first = this.options().proficiencies.find(option => option.id === rule.optionIds[0]);
    return this.options().proficiencies.filter(option => (!first || option.type === first.type) && matches(option));
  }
  private setBusy(value: boolean): void { this.busy.set(value); this.busyChange.emit(value); }
  save(): void {
    if (this.busy() || this.readOnly()) return;
    this.setBusy(true); this.error.set('');
    const { slug, proficiencyRules, languageRules, ...descriptive } = this.draft;
    this.api.saveBackground(this.selected()?.id ?? null, this.selected()?.mechanicsLocked ? descriptive : this.draft)
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.setBusy(false))).subscribe({
        next: item => {
          this.items.update(items => [...items.filter(old => old.id !== item.id), item].sort((a, b) => a.name.localeCompare(b.name, 'fr') || a.id - b.id));
          this.original = JSON.stringify(this.draft); this.opened.set(false);
          this.feedback.set('Historique enregistré.');
          afterNextRender(() => this.createButton()?.nativeElement.focus(), { injector: this.injector });
        },
        error: error => this.showError(error),
      });
  }
  remove(item: CustomBackground): void {
    if (this.busy() || (item.origin === 'OFFICIAL') !== this.official || item.mechanicsLocked || !window.confirm(`Supprimer « ${item.name} » et ses règles ?`)) return;
    this.setBusy(true); this.error.set('');
    this.api.deleteBackground(item.id).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.setBusy(false))).subscribe({
      next: () => { this.items.update(items => items.filter(old => old.id !== item.id)); this.feedback.set('Historique supprimé.'); this.createButton()?.nativeElement.focus(); },
      error: error => this.showError(error),
    });
  }
  private showError(error: unknown): void {
    this.error.set(error instanceof HttpErrorResponse && typeof error.error?.message === 'string' ? error.error.message : 'L’opération a échoué. Réessaie.');
  }
}

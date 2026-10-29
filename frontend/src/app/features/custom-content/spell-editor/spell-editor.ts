import { REFERENCE_READ_ONLY } from '@core/services/reference-editor-context';
import { afterNextRender, Component, DestroyRef, ElementRef, inject, Injector, output, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, forkJoin, Subscription } from 'rxjs';
import { CustomContentApiService } from '@core/services/custom-content-api.service';
import { Spell, SpellOptions, SpellPage, SpellPayload } from '@core/models/spell.model';
import { SpellGrants } from './spell-grants';

@Component({
  selector: 'app-spell-editor', imports: [FormsModule, SpellGrants],
  templateUrl: './spell-editor.html', styleUrls: ['../resource-editor/resource-editor.scss', './spell-editor.scss'],
})
export class SpellEditor {
  private readonly api = inject(CustomContentApiService);
  readonly official = this.api.scope === 'official';
  readonly viewOrigin = this.api.viewOrigin;
  classSearch = '';
  classChoices() { return this.options().classes.filter(c => this.draft.classIds.includes(c.id) || c.name.toLocaleLowerCase('fr').includes(this.classSearch.toLocaleLowerCase('fr'))); }
  readonly contextReadOnly = inject(REFERENCE_READ_ONLY);
  readOnly(): boolean { return this.contextReadOnly || this.selected() !== null && (this.selected()!.origin === 'OFFICIAL') !== this.official; }
  private readonly destroyRef = inject(DestroyRef);
  private readonly injector = inject(Injector);
  private readonly nameInput = viewChild<ElementRef<HTMLInputElement>>('nameInput');
  private readonly createButton = viewChild<ElementRef<HTMLButtonElement>>('createButton');
  private trigger: HTMLElement | null = null;
  private pending?: Subscription;
  private original = '';
  private readonly grants = viewChild(SpellGrants);
  readonly busyChange = output<boolean>();
  readonly busy = signal(false);
  readonly loading = signal(false);
  readonly error = signal('');
  readonly feedback = signal('');
  readonly options = signal<SpellOptions>({ schools: [], classes: [] });
  readonly result = signal<SpellPage>({ spells: [], total: 0, page: 1, pageSize: 25 });
  readonly opened = signal(false);
  readonly selected = signal<Spell | null>(null);
  filters = { q: '', level: '', school: '', origin: '', source: '', classId: '' };
  draft: SpellPayload = this.blank();
  readonly textFields = [
    { key: 'castingTime', label: 'Temps d’incantation', required: true },
    { key: 'range', label: 'Portée', required: true }, { key: 'duration', label: 'Durée', required: true },
    { key: 'description', label: 'Effets', required: true }, { key: 'higherLevels', label: 'Aux niveaux supérieurs', required: false },
    { key: 'materialDescription', label: 'Composantes matérielles', required: false },
  ] as const;
  readonly flags = [
    { key: 'verbal', label: 'Verbale (V)' }, { key: 'somatic', label: 'Somatique (S)' },
    { key: 'material', label: 'Matérielle (M)' }, { key: 'materialConsumed', label: 'Consommation habituelle à l’incantation' },
    { key: 'concentration', label: 'Concentration' }, { key: 'ritual', label: 'Rituel' },
  ] as const;

  constructor() { this.load(); }

  private blank(): SpellPayload {
    return { slug: '', name: '', originalName: null, level: 0, school: 'abjuration', castingTime: '', range: '', duration: '', description: '', higherLevels: null,
      materialDescription: null, materialCostCp: null, rulesVariant: null, sourceCode: null, sourceReference: null, sourceUrl: null,
      verbal: false, somatic: false, material: false, materialConsumed: false, concentration: false, ritual: false, classIds: [] };
  }

  load(page = 1): void {
    if (this.busy() || !this.canDiscard()) return;
    this.opened.set(false);
    this.pending?.unsubscribe(); this.loading.set(true); this.error.set('');
    this.pending = forkJoin({ result: this.api.spells({ ...this.filters, page }), options: this.api.spellOptions() })
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.loading.set(false)))
      .subscribe({ next: ({ result, options }) => { this.result.set(result); this.options.set(options); }, error: error => this.showError(error) });
  }

  open(item: Spell | null, event: Event): void {
    if ((!item && this.contextReadOnly) || this.busy() || !this.canDiscard()) return;
    this.trigger = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    this.selected.set(item);
    this.draft = this.blank();
    if (item) for (const key of Object.keys(this.draft) as (keyof SpellPayload)[]) Object.assign(this.draft, { [key]: structuredClone(item[key]) });
    this.original = JSON.stringify(this.draft); this.opened.set(true); this.error.set(''); this.feedback.set('');
    afterNextRender(() => this.nameInput()?.nativeElement.focus(), { injector: this.injector });
  }

  canDiscard(): boolean {
    return !this.busy() && (this.grants()?.canDiscard() ?? true) && (!this.opened() || this.readOnly() || JSON.stringify(this.draft) === this.original || window.confirm('Abandonner les modifications du sort ?'));
  }

  close(): void {
    if (!this.canDiscard()) return;
    this.opened.set(false); this.restoreFocus();
  }
  private restoreFocus(): void {
    afterNextRender(() => (this.trigger?.isConnected ? this.trigger : this.createButton()?.nativeElement)?.focus(), { injector: this.injector });
  }
  private setBusy(value: boolean): void { this.busy.set(value); this.busyChange.emit(value); }
  grantBusy(value: boolean): void { this.setBusy(value); }
  private showError(error: unknown): void {
    this.error.set(error instanceof HttpErrorResponse && typeof error.error?.message === 'string' ? error.error.message : 'Opération impossible. Vous pouvez réessayer.');
  }
  toggleClass(id: number, checked: boolean): void {
    this.draft.classIds = checked ? [...new Set([...this.draft.classIds, id])] : this.draft.classIds.filter(value => value !== id);
  }

  save(): void {
    if (this.busy() || this.readOnly() || !(this.grants()?.canDiscard() ?? true)) return;
    this.setBusy(true); this.error.set('');
    const { slug, ...update } = this.draft;
    this.api.saveSpell(this.selected()?.id ?? null, this.selected() ? update : this.draft)
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.setBusy(false)))
      .subscribe({ next: () => {
        this.original = JSON.stringify(this.draft); this.opened.set(false); this.setBusy(false);
        this.feedback.set('Sort enregistré.'); this.load(this.result().page); this.restoreFocus();
      }, error: error => this.showError(error) });
  }

  remove(item: Spell): void {
    if (this.busy() || (item.origin === 'OFFICIAL') !== this.official || !item.deletable || !this.canDiscard() || !window.confirm(`Supprimer définitivement « ${item.name} » ?`)) return;
    this.setBusy(true); this.error.set('');
    this.api.deleteSpell(item.id).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.setBusy(false))).subscribe({
      next: () => { this.opened.set(false); this.setBusy(false); this.feedback.set('Sort supprimé.'); this.load(1); this.restoreFocus(); },
      error: error => this.showError(error),
    });
  }
}

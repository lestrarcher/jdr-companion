import { ReferencePicker } from '../../dnd-reference/components/reference-picker/reference-picker';
import { Component, DestroyRef, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, forkJoin } from 'rxjs';
import { CustomContentApiService } from '@core/services/custom-content-api.service';
import { SpellGrant, SpellGrantOptions, SpellGrantPayload } from '@core/models/spell.model';

@Component({ selector: 'app-spell-grants', imports: [FormsModule, ReferencePicker], templateUrl: './spell-grants.html', styleUrls: ['../resource-editor/resource-editor.scss'] })
export class SpellGrants {
  subclassChoices() { return this.options().subclasses.map(s => ({ ...s, name: s.name + ' — ' + s.className })); }
  readonly spellId = input.required<number>();
  readonly disabled = input(false);
  readonly busyChange = output<boolean>();
  private readonly api = inject(CustomContentApiService);
  private readonly destroyRef = inject(DestroyRef);
  readonly opened = signal(false);
  readonly loading = signal(false);
  readonly busy = signal(false);
  readonly error = signal('');
  readonly rows = signal<SpellGrant[]>([]);
  readonly options = signal<SpellGrantOptions>({ subclasses: [], classes: [], resources: [], modes: [] });
  readonly editing = signal(false);
  readonly selected = signal<SpellGrant | null>(null);
  draft = this.blank();
  private original = '';
  private blank(): SpellGrantPayload { return { quotaImpact: 'unspecified', subclassId: null, mode: 'add-to-list', unlockLevel: 1, castingClassId: null, slotUsage: null, restrictions: null, resourceId: null, resourceCost: null }; }
  private setBusy(value: boolean): void { this.busy.set(value); this.busyChange.emit(value); }
  private fail(e: unknown): void { this.error.set(e instanceof HttpErrorResponse && typeof e.error?.message === 'string' ? e.error.message : 'Chargement ou enregistrement impossible. Réessayez.'); }
  load(): void {
    if (this.busy() || this.disabled() || !this.canDiscard()) return;
    this.editing.set(false); this.opened.set(true); this.loading.set(true); this.error.set('');
    forkJoin({ rows: this.api.spellGrants(this.spellId()), options: this.api.spellGrantOptions() }).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.loading.set(false))).subscribe({ next: ({ rows, options }) => { this.rows.set(rows); this.options.set(options); }, error: e => this.fail(e) });
  }
  canDiscard(): boolean { return !this.busy() && (!this.editing() || JSON.stringify(this.draft) === this.original || window.confirm('Abandonner les modifications de l’attribution ?')); }
  edit(row: SpellGrant | null): void {
    if (this.disabled() || (row && !row.editable) || !this.canDiscard()) return;
    this.selected.set(row); this.draft = this.blank();
    if (row) for (const key of Object.keys(this.draft) as (keyof SpellGrantPayload)[]) Object.assign(this.draft, { [key]: row[key] });
    this.draft.quotaImpact ??= 'unspecified';
    this.original = JSON.stringify(this.draft); this.editing.set(true); this.error.set('');
  }
  cancel(): void { if (this.canDiscard()) this.editing.set(false); }
  changeMode(): void {
    if (this.draft.mode !== 'grant-cast') { this.draft.slotUsage = null; this.draft.restrictions = null; this.draft.resourceId = null; this.draft.resourceCost = null; }
  }
  save(): void {
    if (this.busy() || this.disabled() || (this.selected() && !this.selected()!.editable)) return;
    const { subclassId, ...update } = this.draft;
    this.setBusy(true); this.error.set('');
    this.api.saveSpellGrant(this.spellId(), this.selected()?.id ?? null, this.selected() ? update : this.draft).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.setBusy(false))).subscribe({ next: ({ grant }) => { this.rows.update(rows => [...rows.filter(r => r.id !== grant.id), grant]); this.editing.set(false); }, error: e => this.fail(e) });
  }
  remove(row: SpellGrant): void {
    if (this.disabled() || !row.editable || !this.canDiscard() || !window.confirm('Supprimer cette attribution ?')) return;
    this.setBusy(true); this.error.set('');
    this.api.deleteSpellGrant(this.spellId(), row.id).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.setBusy(false))).subscribe({ next: () => { this.rows.update(rows => rows.filter(r => r.id !== row.id)); this.editing.set(false); }, error: e => this.fail(e) });
  }
  label(mode: string): string { return this.options().modes.find(m => m.value === mode)?.label ?? mode; }
  slotLabel(value: string | null): string { return ({ required: 'Requis', 'not-required': 'Non requis', either: 'Avec ou sans emplacement' } as Record<string, string>)[value ?? ''] ?? 'Non applicable'; }
}

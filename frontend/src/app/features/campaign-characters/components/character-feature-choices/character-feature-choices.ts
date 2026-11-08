import { Component, computed, DestroyRef, effect, inject, input, output, signal, untracked } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { Observable } from 'rxjs';
import { FeatureChoiceGrant, FeatureChoiceLoader, FeatureChoiceOption, FeatureChoiceRepair, FeatureChoiceSelection, FeatureChoiceView } from '@core/services/feature-choices-api.service';

@Component({
  selector: 'app-character-feature-choices',
  imports: [FormsModule],
  templateUrl: './character-feature-choices.html',
  styleUrls: ['../character-level-up/character-level-up.scss', './character-feature-choices.scss'],
})
export class CharacterFeatureChoices {
  private readonly destroyRef = inject(DestroyRef);
  readonly load = input.required<FeatureChoiceLoader>();
  readonly classKey = input('');
  readonly subclassKey = input<string | null>(null);
  readonly activated = input(true);
  readonly disabled = input(false);
  readonly save = input<((payload: FeatureChoiceRepair) => Observable<FeatureChoiceView>) | null>(null);
  readonly changed = output<{ selections: FeatureChoiceSelection[]; complete: boolean }>();
  readonly saved = output<void>();
  protected readonly view = signal<FeatureChoiceView | null>(null);
  protected readonly loading = signal(false);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly stale = signal(false);
  protected readonly reason = signal('');
  protected readonly selections = signal<FeatureChoiceSelection[]>([]);
  private readonly reload = signal(0);
  protected readonly complete = computed(() => !this.loading() && !!this.view() && !this.stale()
    && (this.save() !== null || this.view()!.grants.filter(g => g.newAtLevel).every(g =>
      this.slots(g).every(slot => !!this.value(g, slot) || g.selected.some(s => s.slot === slot && s.valid)))));

  constructor() {
    effect(onCleanup => {
      const load = this.load(), cls = this.classKey(), sub = this.subclassKey(), active = this.activated(); this.reload();
      untracked(() => { this.view.set(null); this.selections.set([]); this.error.set(null); this.stale.set(false); this.loading.set(active); });
      if (!active) return;
      const request = load(cls, sub).subscribe({
        next: view => { this.view.set(view); this.loading.set(false); },
        error: e => { this.error.set(this.message(e)); this.loading.set(false); },
      });
      onCleanup(() => request.unsubscribe());
    });
    effect(() => this.changed.emit({ selections: this.selections(), complete: this.complete() }));
  }
  protected retry(): void { if (!this.saving() && !this.disabled()) this.reload.update(v => v + 1); }
  protected unavailable(option: FeatureChoiceOption): boolean {
    // These backend refusals cannot be resolved by another choice in this same form.
    return option.reasons.some(r => r.startsWith('classLevels:') || r.startsWith('subclasses:') || r === 'unsupported_prerequisite' || r === 'invalid_prerequisite');
  }
  protected slots(g: FeatureChoiceGrant): number[] { return Array.from({ length: g.choice_count }, (_, i) => i + 1); }
  protected current(g: FeatureChoiceGrant, slot: number) { return g.selected.find(s => s.slot === slot); }
  protected value(g: FeatureChoiceGrant, slot: number): string { return this.selections().find(s => s.grantKey === g.key && s.slot === slot)?.optionKey ?? ''; }
  protected editable(g: FeatureChoiceGrant, slot: number): boolean {
    return this.current(g, slot) ? !!this.save() || g.replacementAvailable : !!this.save() || !!g.newAtLevel;
  }
  protected select(g: FeatureChoiceGrant, slot: number, optionKey: string): void {
    if (this.disabled() || this.saving() || !this.editable(g, slot)
      || (optionKey && !g.options.some(o => o.key === optionKey && !this.unavailable(o)))) return;
    const rest = this.selections().filter(s => !(s.grantKey === g.key && s.slot === slot));
    const current = this.current(g, slot);
    this.selections.set(optionKey && optionKey !== current?.optionKey
      ? [...rest, { grantKey: g.key, slot, optionKey, ...(current ? { replacesKey: current.key } : {}) }] : rest);
  }
  protected option(g: FeatureChoiceGrant, slot: number) { return g.options.find(o => o.key === (this.value(g, slot) || this.current(g, slot)?.optionKey)); }
  protected label(grantKey: string, optionKey: string): string {
    return this.view()?.grants.find(g => g.key === grantKey)?.options.find(o => o.key === optionKey)?.name ?? optionKey;
  }
  protected requirement(value: unknown): string {
    if (Array.isArray(value)) return value.map(v => this.requirement(v)).join(' ; ');
    if (value && typeof value === 'object') return Object.entries(value).map(([k,v]) => `${this.reasonLabel(k)} : ${this.requirement(v)}`).join(' ; ');
    return String(value);
  }
  protected reasonLabel(reason: string): string {
    const [kind, ...detail] = reason.split(':');
    const labels: Record<string, string> = { classLevels: 'Niveau de classe', subclasses: 'Sous-classe', features: 'Capacité requise', feats: 'Don requis', spellsKnown: 'Sort connu requis', anyOf: 'Au moins une condition', already_selected: 'Déjà sélectionné', unsupported_prerequisite: 'Prérequis non pris en charge', invalid_prerequisite: 'Prérequis invalide' };
    return (labels[kind] ?? kind) + (detail.length ? ` : ${detail.join(':')}` : '');
  }
  protected confirm(): void {
    const save = this.save(), view = this.view();
    if (!save || !view || !this.complete() || this.saving() || !this.reason().trim() || !this.selections().length) return;
    this.saving.set(true); this.error.set(null);
    save({ fingerprint: view.fingerprint, reason: this.reason().trim(), selections: this.selections() }).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: view => { this.view.set(view); this.selections.set([]); this.reason.set(''); this.saving.set(false); this.saved.emit(); },
      error: e => { this.error.set(this.message(e)); this.stale.set(e?.status === 409); this.saving.set(false); },
    });
  }
  private message(e: { error?: { message?: string; detail?: string } }): string { return e?.error?.message ?? e?.error?.detail ?? 'Impossible de charger ou enregistrer les choix de capacités.'; }
}

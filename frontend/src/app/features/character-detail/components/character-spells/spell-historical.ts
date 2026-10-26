import { Component, DestroyRef, inject, input, output, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { catchError, forkJoin, map, of, switchMap } from 'rxjs';
import { spellArtwork } from '../../../player-portal/components/spell-quick-list/spell-quick-list';

type Spell = { id: number; name: string; level: number; school?: string | null };
type Choice = { spellId: number; ruleId: number | null };
type Category = { ruleId: number | null; key: string; label: string; quota: string; maximum: number; remaining: number; known: { id: number; name: string }[]; available: Spell[] };
type HistoricalClass = { policyId: number | null; className: string; warning: string; limitations?: string[]; expectedAcquisitionIds: number[]; categories: Category[] };
type View = { revision: number; editable: boolean; classes: HistoricalClass[] };

@Component({
  selector: 'app-spell-historical', imports: [FormsModule],
  templateUrl: './spell-historical.html', styleUrls: ['../../../character-spellbook/spell-revision.scss', './spell-historical.scss'],
})
export class SpellHistorical {
  artwork(spell: Spell): string | null {
    return spellArtwork({ spellId: spell.id, school: spell.school ?? null });
  }
  onArtError(event: Event, spell: Spell): void {
    const image = event.target;
    if (!(image instanceof HTMLImageElement)) return;
    const fallback = spellArtwork({ spellId: 0, school: spell.school ?? null });
    if (fallback && image.getAttribute('src') !== fallback) image.src = fallback;
    else image.hidden = true;
  }
  readonly characterId = input(0);
  readonly accessToken = input('');
  readonly disabled = input(false);
  readonly saved = output<void>();
  readonly open = signal(false);
  readonly busy = signal(false);
  readonly view = signal<View | null>(null);
  readonly error = signal('');
  readonly success = signal('');
  readonly selections = signal<Record<number, Choice[]>>({});
  query: Record<string, string> = {};
  levelFilter: Record<string, number> = {};
  confirmed: Record<number, boolean> = {};
  expandedCategory: string | null = null;
  categoryId(source: HistoricalClass, category: Category): string { return `historical-${source.policyId ?? 'unknown'}-${category.key.replace(/[^a-zA-Z0-9-]/g, '-')}`; }
  isExpanded(source: HistoricalClass, category: Category): boolean { return this.expandedCategory === this.key(source, category); }
  toggleCategory(source: HistoricalClass, category: Category): void {
    const key = this.key(source, category);
    this.expandedCategory = this.expandedCategory === key ? null : key;
  }
  categoryIcon(category: Category): string { return category.label.startsWith('arcanum-') ? '✧' : ['cantrips'].includes(category.label) ? '✦' : '◈'; }
  categoryDescription(category: Category): string {
    if (category.remaining === 0) return 'Catégorie complète';
    return `${category.remaining} sort(s) manquant(s) · ${category.maximum} au total`;
  }
  saveCategory(source: HistoricalClass, category: Category): void {
    if (!source.policyId || !this.selectedCount(source, category)) return;
    // Submit only this category; the other draft selections remain in memory and are
    // revalidated against the fresh server quotas after the successful write.
    this.save(source, category);
  }
  private readonly http = inject(HttpClient);
  private readonly destroy = inject(DestroyRef);
  key(source: HistoricalClass, category: Category): string { return `${source.policyId}:${category.key}`; }
  label(category: Category): string {
    if (category.label.startsWith('arcanum-')) return `Arcanum mystique de niveau ${category.label.slice(8)}`;
    return ({ cantrips: 'Sorts mineurs', ordinary: 'Sorts connus', known: 'Sorts connus', book: 'Grimoire', spellbook: 'Grimoire', restricted: 'Écoles autorisées', unrestricted: 'Choix exceptionnel' } as Record<string,string>)[category.label] ?? category.label;
  }
  selected(source: HistoricalClass): Choice[] { return this.selections()[source.policyId ?? 0] ?? []; }
  selectedCount(source: HistoricalClass, category: Category): number {
    return this.selected(source).filter(choice => choice.ruleId === category.ruleId && category.available.some(s => s.id === choice.spellId)).length;
  }
  isSelected(source: HistoricalClass, spellId: number): boolean { return this.selected(source).some(choice => choice.spellId === spellId); }
  levels(category: Category): number[] { return [...new Set(category.available.map(s => s.level))].sort((a,b) => a-b); }
  selectedSpells(source: HistoricalClass, category: Category): Spell[] {
    return category.available.filter(spell => this.selected(source).some(choice => choice.spellId === spell.id && choice.ruleId === category.ruleId));
  }
  candidates(source: HistoricalClass, category: Category): Spell[] {
    const key = this.key(source, category), query = (this.query[key] ?? '').trim().toLocaleLowerCase('fr'), level = this.levelFilter[key] ?? -1;
    return category.available.filter(s => (level === -1 || s.level === Number(level)) && s.name.toLocaleLowerCase('fr').includes(query));
  }
  toggleChoice(source: HistoricalClass, category: Category, spell: Spell): void {
    if (!source.policyId || this.busy() || this.disabled() || !this.view()?.editable) return;
    const selected = this.selected(source);
    if (!this.isSelected(source, spell.id) && (this.selectedCount(source, category) >= category.remaining || !category.available.some(s => s.id === spell.id))) return;
    this.selections.update(current => ({ ...current, [source.policyId!]: this.isSelected(source, spell.id)
      ? selected.filter(c => c.spellId !== spell.id) : [...selected, { spellId: spell.id, ruleId: category.ruleId }] }));
    this.confirmed[source.policyId] = false; this.success.set('');
  }
  hasChoices(source: HistoricalClass): boolean { return source.categories.some(c => c.remaining > 0); }
  toggle(): void { this.open.update(value => !value); if (this.open()) this.load(); }
  load(): void {
    this.busy.set(true); this.error.set(''); this.view.set(null);
    const request = this.accessToken() ? this.http.get<View>(`/api/public/characters/${this.accessToken()}/spells/historical`)
      : this.http.get<{ policies: { policyIds: number[]; className: string; status: string }[] }>(`/api/characters/${this.characterId()}/spell-acquisitions`).pipe(
        switchMap(({ policies }) => policies.length ? forkJoin(policies.map(policy => {
          const empty: HistoricalClass = { policyId: null, className: policy.className, categories: [], expectedAcquisitionIds: [], warning: 'Politique à vérifier avec le MJ.' };
          return policy.status !== 'policy-configured' ? of(empty)
            : this.http.get<HistoricalClass>(`/api/characters/${this.characterId()}/spell-acquisitions/${policy.policyIds[0]}/historical-preview`).pipe(
              map(preview => ({ ...preview, className: policy.className })), catchError((error: HttpErrorResponse) => of({ ...empty, warning: this.message(error) })));
        })) : of([] as HistoricalClass[])), map(classes => ({ classes, revision: 0, editable: true })));
    request.pipe(takeUntilDestroyed(this.destroy)).subscribe({ next: view => {
      const kept: Record<number, Choice[]> = {};
      for (const source of view.classes) if (source.policyId) {
        const counts: Record<string, number> = {};
        kept[source.policyId] = this.selected(source).filter(choice => {
          const category = source.categories.find(c => c.ruleId === choice.ruleId && c.available.some(s => s.id === choice.spellId));
          if (!category || (counts[category.key] ?? 0) >= category.remaining) return false;
          counts[category.key] = (counts[category.key] ?? 0) + 1; return true;
        });
        this.confirmed[source.policyId] = false;
      }
      this.selections.set(kept); this.view.set(view); this.busy.set(false);
    }, error: error => { this.error.set(this.message(error)); this.busy.set(false); } });
  }
  save(source: HistoricalClass, category?: Category): void {
    const choices = category ? this.selected(source).filter(choice => choice.ruleId === category.ruleId && category.available.some(spell => spell.id === choice.spellId)) : this.selected(source);
    if (this.busy() || this.disabled() || !this.view()?.editable || !source.policyId || !choices.length || (!category && !this.confirmed[source.policyId]) || source.warning) return;
    this.busy.set(true); this.error.set(''); this.success.set('');
    const payload = { choices, expectedAcquisitionIds: source.expectedAcquisitionIds, confirmed: true };
    const request = this.accessToken()
      ? this.http.post(`/api/public/characters/${this.accessToken()}/spells/historical-completion`, { ...payload, policyId: source.policyId, revision: this.view()!.revision })
      : this.http.post(`/api/characters/${this.characterId()}/spell-acquisitions/${source.policyId}/historical-completion`, payload);
    request.pipe(takeUntilDestroyed(this.destroy)).subscribe({
      next: () => {
        this.selections.update(value => ({ ...value, [source.policyId!]: category
          ? (value[source.policyId!] ?? []).filter(choice => !choices.some(saved => saved.spellId === choice.spellId && saved.ruleId === choice.ruleId))
          : [] })); this.confirmed[source.policyId!] = false;
        this.success.set('Sélection ajoutée. Tous les sorts existants sont conservés.'); this.saved.emit(); this.load();
      },
      // Preserve selection on refusals, including a failed feasibility proof. Reload is explicit.
      error: (error: HttpErrorResponse) => { this.busy.set(false); this.error.set(this.message(error)); },
    });
  }
  private message(error: HttpErrorResponse): string { return error.error?.message || error.error?.detail || 'Impossible de charger ou compléter les sorts.'; }
}

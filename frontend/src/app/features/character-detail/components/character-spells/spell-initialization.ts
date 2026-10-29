import { Component, computed, effect, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Subscription } from 'rxjs';
import { CharacterApiService } from '@core/services/character-api.service';
import { InitialSpell, SpellInitializationPreview } from '@core/models/spell-initialization.model';

interface Category {
  key: string; policyId: number; ruleId: number | null; label: string;
  total: number; used: number; remaining: number; restrictions: string; spells: InitialSpell[]; acquiredIds: Set<number>;
}

@Component({
  selector: 'app-spell-initialization', standalone: true, imports: [FormsModule],
  templateUrl: './spell-initialization.html', styleUrl: './spell-initialization.scss',
})
export class SpellInitialization {
  private readonly api = inject(CharacterApiService);
  readonly characterId = input.required<number>();
  readonly totalLevel = input.required<number>();
  readonly saved = output<void>();
  readonly preview = signal<SpellInitializationPreview | null>(null);
  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly error = signal('');
  readonly success = signal(false);
  readonly search = signal('');
  readonly activeCategory = signal<string | null>(null);
  readonly expandedSpell = signal<number | null>(null);
  readonly filteredSpells = (category: Category): InitialSpell[] => category.spells.filter(spell => `${spell.name} ${spell.school}`.toLocaleLowerCase('fr').includes(this.search().toLocaleLowerCase('fr')));
  readonly selectedCount = (category: Category): number => (this.selection()[category.key] ?? []).length;
  readonly selection = signal<Record<string, number[]>>({});
  readonly categories = computed<Category[]>(() => this.preview()?.classes.flatMap<Category>(c => {
    const o = c.options;
    if (c.status !== 'policy-configured' || !o || o.blocked.length || o.status === 'quota-exceeded') return [];
    const occupied = new Set(o.acquisitions.map(a => Number(a.spell_id)));
    o.automatic.filter(a => a.bucket !== null).forEach(a => occupied.add(a.spellId));
    const eligible = (spells: InitialSpell[]) => spells;
    if (o.formatVersion === 2) return (o.choices ?? []).map(choice => {
      const p = c.restrictions.find(r => r.ruleId === choice.ruleId)?.parameters;
      const total = p?.opportunities[c.classLevel - 1] ?? choice.remaining;
      return { key: `${o.policyId}:${choice.ruleId}`, policyId: o.policyId, ruleId: choice.ruleId,
        label: `${c.className} — ${choice.quota === 'cantrips' ? 'Tours de magie' : choice.result === 'spellbook' ? 'Sorts du grimoire' : 'Sorts connus'}`,
        total, used: total - choice.remaining, remaining: choice.remaining,
        restrictions: p ? `Niveaux ${p.minimumSpellLevel} à ${p.maxSpellLevels[c.classLevel - 1]}. Écoles : ${p.schools.length ? p.schools.join(', ') : 'toutes'}.` : '',
        spells: eligible(choice.available), acquiredIds: occupied };
    });
    return (['cantrips', ...(o.learningStyle === 'known' ? ['known'] : o.learningStyle === 'spellbook' ? ['spellbook'] : [])] as Array<'cantrips' | 'known' | 'spellbook'>).map(bucket => ({
      key: `${o.policyId}:${bucket}`, policyId: o.policyId, ruleId: null,
      label: `${c.className} — ${{ cantrips: 'Tours de magie connus', known: 'Sorts connus', spellbook: 'Sorts de grimoire' }[bucket]}`,
      total: bucket === 'spellbook' ? o.limits.bookAdditions : o.limits[bucket], used: o.used[bucket], remaining: o.remaining[bucket],
      restrictions: `Niveau maximal : ${bucket === 'cantrips' ? 0 : o.limits.maxSpellLevel}. ${(o.limitations ?? []).join(' ')}`,
      spells: eligible(o.available).filter(s => (s.level === 0) === (bucket === 'cantrips')), acquiredIds: occupied,
    }));
  }) ?? []);
  readonly isAcquired = (category: Category, spellId: number): boolean => category.acquiredIds.has(spellId);
  readonly pending = computed(() => this.categories().some(c => c.remaining > 0));
  private requests = new Subscription();

  constructor() {
    effect(onCleanup => {
      const id = this.characterId(); const level = this.totalLevel();
      this.requests = new Subscription();
      this.preview.set(null); this.selection.set({}); this.search.set(''); this.error.set(''); this.success.set(false); this.saving.set(false);
      this.loading.set(false);
      if (level === 1) this.load(id);
      onCleanup(() => this.requests.unsubscribe());
    });
  }
  private load(id: number): void {
    this.loading.set(true);
    this.requests.add(this.api.getSpellInitialization(id).subscribe({
      next: preview => { this.preview.set(preview); this.loading.set(false); if (!this.activeCategory() || !this.categories().some(c => c.key === this.activeCategory() && c.remaining > 0)) this.activeCategory.set(this.categories().find(c => c.remaining > 0)?.key ?? null); },
      error: err => { this.preview.set(null); this.error.set(err.error?.error ?? err.error?.message ?? 'Impossible de charger les choix initiaux.'); this.loading.set(false); },
    }));
  }
  retry(): void { if (!this.loading() && !this.saving()) { this.error.set(''); this.load(this.characterId()); } }
  toggle(key: string, id: number): void {
    if (this.saving()) return;
    const category = this.categories().find(c => c.key === key);
    if (!category || category.acquiredIds.has(id)) return;
    const current = this.selection()[key] ?? [];
    if (category && !current.includes(id) && current.length >= category.remaining) return;
    this.selection.update(values => ({ ...values, [key]: (values[key] ?? []).includes(id) ? values[key].filter(v => v !== id) : [...(values[key] ?? []), id] }));
  }
  save(category: Category): void {
    const ids = this.selection()[category.key] ?? [];
    if (!this.preview() || this.saving() || this.loading() || !ids.length || ids.length > category.remaining) return;
    this.saving.set(true); this.error.set(''); this.success.set(false);
    this.requests.add(this.api.initializeSpells(this.characterId(), {
      policyId: category.policyId, reason: 'Sélection initiale', choices: ids.map(spellId => ({spellId, ruleId: category.ruleId})),
    }).subscribe({
      next: () => {
        this.selection.set({}); this.saving.set(false); this.success.set(true);
        this.load(this.characterId()); this.saved.emit();
      },
      error: err => { this.saving.set(false); this.error.set(err.error?.error ?? err.error?.message ?? 'Impossible d’enregistrer les sorts.'); },
    }));
  }
}

import { Component, DestroyRef, OnChanges, OnInit, computed, inject, input, output, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Location } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable, finalize } from 'rxjs';
import { PlayerSpell } from '@core/services/character-session-state-api.service';
import { PlayerSpellApiService, PlayerSpellChoice, PlayerSpellView } from '@core/services/player-spell-api.service';

@Component({
  selector: 'app-player-spells',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './player-spells.html',
  styleUrl: './player-spells.scss',
})
export class PlayerSpells implements OnInit, OnChanges {
  readonly spells = input.required<PlayerSpell[]>();
  readonly accessToken = input('');
  readonly readonly = input(true);
  readonly revision = input(0);
  readonly prioritizePreparation = input(false);
  readonly mode = input<'portal' | 'spellbook' | 'details'>('portal');
  readonly detailSpell = input<PlayerSpell | null>(null);
  readonly changed = output<void>();
  private readonly api = inject(PlayerSpellApiService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly location = inject(Location);
  private skipPreparationRedirect = false;
  readonly view = signal<PlayerSpellView | null>(null);
  readonly busy = signal(false);
  readonly error = signal('');
  readonly success = signal('');
  readonly preparationAvailable = computed(() => this.view()?.spellPreparationAllowed === true
    && (this.view()?.preparations.length ?? 0) > 0);
  readonly displayed = computed(() => {
    const view = this.view();
    const spells = view && view.revision > this.revision() ? view.spells : this.spells();
    return this.mode() === 'spellbook' ? spells.filter(spell => spell.inSpellbook) : spells;
  });
  private readonly selection = signal<PlayerSpell | null>(null);
  readonly quickSpells = computed(() => this.displayed().filter(spell => spell.level === 0
    || (spell.level !== null && spell.level >= 1 && (spell.prepared === true || spell.alwaysPrepared === true
      || (spell.known && !spell.inSpellbook))))
    .sort((a, b) => (a.level ?? Infinity) - (b.level ?? Infinity) || a.name.localeCompare(b.name, 'fr')));
  readonly selectedSpell = computed(() => {
    const selected = this.mode() === 'details' ? this.detailSpell() : this.selection();
    return selected === null ? null : this.displayed().find(spell => this.spellKey(spell) === this.spellKey(selected)) ?? selected;
  });
  readonly hasSpellbook = computed(() => this.displayed().some(spell => spell.inSpellbook)
    || this.view()?.preparations.some(plan => plan.learningStyle === 'spellbook') === true);
  readonly sections = computed(() => this.mode() === 'spellbook'
    ? [...new Set(this.displayed().map(spell => spell.level))].sort((a, b) => (a ?? 10) - (b ?? 10)).map(level => ({
        key: `level-${level ?? 'unknown'}`, title: level === null ? 'Niveau inconnu' : level === 0 ? 'Tours de magie' : `Niveau ${level}`,
        description: '', spells: this.displayed().filter(spell => spell.level === level),
      }))
    : [
    { key: 'known', title: 'Sorts connus', description: 'Tours de magie et sorts appris ou octroyés.', spells: this.displayed().filter(spell => spell.known) },
    { key: 'prepared', title: 'Sorts préparés', description: 'Votre sélection actuelle et les sorts toujours préparés.', spells: this.displayed().filter(spell => spell.prepared === true) },
  ]);
  readonly editable = computed(() => !this.readonly() && this.view()?.editable === true && !this.busy());
  readonly selections: Record<string, number> = {};
  spellKey(spell: PlayerSpell): string { return spell.key ?? `${spell.sourceType}:${spell.source}:${spell.spellId}`; }
  select(spell: PlayerSpell): void {
  const current = this.selection();

  if (current && this.spellKey(current) === this.spellKey(spell)) {
    this.selection.set(null);
  } else {
    this.selection.set(spell);
  }
}
  readonly schoolIcon = (school: string | null): string => ({
    abjuration: '⬡', conjuration: '◇', divination: '◉', enchantment: '❋',
    evocation: 'ϟ', illusion: '◈', necromancy: '☽', transmutation: '△',
  } as Record<string, string>)[school ?? ''] ?? '✧';
  readonly schoolLabel = (school: string | null): string => ({
    abjuration: 'Abjuration', conjuration: 'Invocation', divination: 'Divination', enchantment: 'Enchantement',
    evocation: 'Évocation', illusion: 'Illusion', necromancy: 'Nécromancie', transmutation: 'Transmutation',
  } as Record<string, string>)[school ?? ''] ?? 'École non renseignée';
  ngOnInit(): void {
    if (this.prioritizePreparation()) {
      const state = this.location.getState() as { skipSpellPreparationRedirect?: boolean };
      this.skipPreparationRedirect = state?.skipSpellPreparationRedirect === true;
    }
    if (this.accessToken()) this.reload();
  }
  ngOnChanges(): void {
    if (this.accessToken() && this.view() && this.revision() > this.view()!.revision && !this.busy()) this.reload();
  }
  reload(): void {
    this.busy.set(true);
    this.api.get(this.accessToken()).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.busy.set(false))).subscribe({
      next: view => {
        this.view.set(view);
        if (!view.spellPreparationAllowed) this.skipPreparationRedirect = false;
        if (this.prioritizePreparation() && this.mode() === 'portal' && !this.skipPreparationRedirect
          && !this.readonly() && view.editable && this.preparationAvailable()) {
          void this.router.navigate(['prepare-spells'], { relativeTo: this.route, replaceUrl: true });
        }
      }, error: error => this.failed(error),
    });
  }
  choose(choice: PlayerSpellChoice): void {
    const spellId = Number(this.selections[choice.key]);
    if (!this.editable() || !spellId || !choice.available.some(spell => spell.id === spellId)) return;
    this.save(this.api.choose(this.accessToken(), this.view()!.revision, choice, spellId), 'Sort appris.');
  }
  private save(request: Observable<PlayerSpellView>, message: string): void {
    this.busy.set(true); this.error.set(''); this.success.set('');
    request.pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: view => {
        this.view.set(view); this.busy.set(false); this.success.set(message);
        for (const key of Object.keys(this.selections)) delete this.selections[key];
        this.changed.emit();
      },
      error: error => { this.busy.set(false); this.failed(error); if (error.status === 409) this.reload(); },
    });
  }
  private failed(error: HttpErrorResponse): void { this.error.set(error.error?.message || error.error?.detail || 'Impossible de charger ou modifier les sorts.'); }
  readonly abilityLabel = (value: string | null): string => ({
    strength: 'Force', dexterity: 'Dextérité', constitution: 'Constitution',
    intelligence: 'Intelligence', wisdom: 'Sagesse', charisma: 'Charisme',
  } as Record<string, string>)[value ?? ''] ?? 'Non définie';
  readonly bonus = (value: number | null): string => value === null ? '—' : `${value >= 0 ? '+' : ''}${value}`;
}

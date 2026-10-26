import { Component, DestroyRef, HostListener, OnChanges, OnInit, SimpleChanges, computed, inject, input, output, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Location } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable, finalize } from 'rxjs';
import { SpellQuickList, spellSchoolIcon } from '../spell-quick-list/spell-quick-list';
import { SpellCastDialog, SpellCastSelection } from '../spell-cast-dialog/spell-cast-dialog';
import { PlayerSpell } from '@core/services/character-session-state-api.service';
import { CharacterResource } from '@core/models/character.model';
import { PlayerSpellApiService, PlayerSpellChoice, PlayerSpellView } from '@core/services/player-spell-api.service';
import { availableSpellCastMethods, createSpellCastCommand, SpellCastCommand, SpellCastResponse } from '@core/models/spell-cast.model';

@Component({
  selector: 'app-player-spells',
  standalone: true,
  imports: [FormsModule, RouterLink, SpellQuickList, SpellCastDialog],
  templateUrl: './player-spells.html',
  styleUrl: './player-spells.scss',
})
export class PlayerSpells implements OnInit, OnChanges {
  readonly spells = input.required<PlayerSpell[]>();
  readonly accessToken = input('');
  readonly readonly = input(true);
  readonly revision = input(0);
  readonly resources = input<readonly Pick<CharacterResource, 'id' | 'currentValue'>[]>([]);
  readonly prioritizePreparation = input(false);
  readonly mode = input<'portal' | 'spellbook' | 'details'>('portal');
  readonly detailSpell = input<PlayerSpell | null>(null);
  readonly quickSelection = input<PlayerSpell | null>(null);
  readonly panelVisible = input(true);
  readonly dialogSpell = signal<PlayerSpell | null>(null);
  readonly dialogOpen = signal(false);
  readonly dialogSources = computed(() => this.displayed().filter(spell => spell.spellId === this.dialogSpell()?.spellId));
  openCastDialog(spell: PlayerSpell): void {
    if (this.mode() !== 'portal' || !this.accessToken() || this.readonly()) return;
    if (!this.pendingCast()) {
      this.dialogSpell.set(spell); this.castError.set(''); this.castSuccess.set('');
    }
    this.dialogOpen.set(true);
  }
  closeCastDialog(): void { if (!this.castSaving()) this.dialogOpen.set(false); }
  selectQuickSpell(spell: PlayerSpell): void {
    this.select(spell);
    this.openCastDialog(spell);
  }
  confirmDialogSelection(choice: SpellCastSelection): void {
    if (!this.dialogOpen() || this.pendingCast() || this.castSuccess()) return;
    const source = this.dialogSources().find(spell => spell.key === choice.sourceKey);
    if (!source) return;
    this.selection.set(source);
    this.castChoice.set(this.castMethodKey({ kind: choice.method, castingLevel: choice.castingLevel }));
    this.confirmCast();
  }
  readonly changed = output<void>();
  readonly castingBlocked = input(false);
  readonly castCompleted = output<SpellCastResponse>();
  readonly casting = output<boolean>();
  readonly castSaving = signal(false);
  readonly pendingCast = signal<{ command: SpellCastCommand; name: string; source: string } | null>(null);
  readonly castError = signal('');
  readonly castSuccess = signal('');
  readonly castUncertain = signal(false);
  readonly castChoice = signal('');
  canLeavePreparation(): boolean {
    return !(this.castSaving() || this.castUncertain()) || window.confirm('Le résultat du lancement est incertain. Quitter perdra la commande permettant de vérifier ce lancement. Quitter quand même ?');
  }
  @HostListener('window:beforeunload', ['$event'])
  protectPendingCast(event: BeforeUnloadEvent): void {
    if (this.castSaving() || this.castUncertain()) { event.preventDefault(); event.returnValue = ''; }
  }
  readonly castingSpell = computed(() => this.displayed().find(spell => spell.key && spell.key === this.selectedSpell()?.key));
  readonly castMethods = computed(() => availableSpellCastMethods(this.castingSpell()));
  readonly chosenCastMethod = computed(() => this.castMethods().find(method => this.castMethodKey(method) === this.castChoice()));
  readonly canCast = computed(() => this.mode() === 'portal' && !!this.accessToken() && !this.readonly()
    && !this.castingBlocked() && !this.busy() && !this.castSaving() && !this.pendingCast() && !!this.chosenCastMethod());
  castMethodKey(method: { kind: string; castingLevel: number | null }): string { return `${method.kind}:${method.castingLevel}`; }
  confirmCast(): void {
    const method = this.chosenCastMethod(); const spell = this.castingSpell();
    if (!this.canCast() || !spell?.key || !method) return;
    this.pendingCast.set({ command: createSpellCastCommand({ revision: Math.max(this.revision(), this.view()?.revision ?? 0),
      sourceKey: spell.key, method: method.kind, castingLevel: method.castingLevel }), name: spell.name, source: spell.source });
    this.sendCast();
  }
  retryCast(): void { if (this.castUncertain() && !this.castSaving() && !this.castingBlocked()) this.sendCast(); }
  dismissCastRefusal(): void {
    if (this.castSaving() || this.castUncertain()) return;
    this.pendingCast.set(null); this.castError.set(''); this.castChoice.set('');
    this.closeCastDialog();
  }
  refreshAfterCastRefusal(): void {
    if (this.castSaving() || this.castUncertain()) return;
    this.dismissCastRefusal(); this.changed.emit();
  }
  private sendCast(): void {
    const pending = this.pendingCast();
    if (!pending || this.castSaving()) return;
    this.castSaving.set(true); this.casting.emit(true); this.castError.set(''); this.castSuccess.set('');
    this.api.cast(this.accessToken(), pending.command).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: response => {
        this.castCompleted.emit(response);
        this.view.update(view => view ? { ...view, spells: response.spells, revision: response.revision } : null);
        this.pendingCast.set(null); this.castChoice.set(''); this.castUncertain.set(false);
        this.castSuccess.set(`${pending.name} · ${pending.source} : lancement confirmé${response.replayed ? ' (déjà enregistré)' : ''}.`);
        this.castSaving.set(false); this.casting.emit(false);
      },
      error: (error: HttpErrorResponse) => {
        const refused = [400, 404, 409, 422].includes(error.status);
        this.castUncertain.set(!refused);
        this.castError.set(refused
          ? ({ 400: 'Commande invalide.', 404: 'Accès au personnage indisponible.', 409: 'Conflit : actualisez la fiche avant une nouvelle tentative.', 422: 'Ce moyen de lancement n’est plus disponible.' }[error.status] ?? 'Lancement refusé.')
          : 'Résultat incertain. Réessayez la même commande pour vérifier le lancement sans le payer deux fois.');
        this.castSaving.set(false); this.casting.emit(false);
      },
    });
  }
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
  readonly selectedSpell = computed(() => {
    const selected = this.mode() === 'details' ? this.detailSpell() : this.selection();
    return selected === null ? null : this.displayed().find(spell => this.spellKey(spell) === this.spellKey(selected)) ?? selected;
  });
  readonly hasSpellbook = computed(() => this.displayed().some(spell => spell.inSpellbook)
    || this.view()?.preparations.some(plan => plan.learningStyle === 'spellbook') === true);
  readonly unpreparedRituals = computed(() => this.displayed().filter(spell => !spell.known && spell.prepared !== true
    && spell.castingAvailability?.methods.some(method => method.kind === 'ritual' && method.status === 'available')));
  readonly sections = computed(() => this.mode() === 'spellbook'
    ? [...new Set(this.displayed().map(spell => spell.level))].sort((a, b) => (a ?? 10) - (b ?? 10)).map(level => ({
        key: `level-${level ?? 'unknown'}`, title: level === null ? 'Niveau inconnu' : level === 0 ? 'Tours de magie' : `Niveau ${level}`,
        description: '', spells: this.displayed().filter(spell => spell.level === level),
      }))
    : [
    { key: 'known', title: 'Sorts connus', description: 'Tours de magie et sorts appris ou octroyés.', spells: this.displayed().filter(spell => spell.known) },
    { key: 'prepared', title: 'Sorts préparés', description: 'Votre sélection actuelle et les sorts toujours préparés.', spells: this.displayed().filter(spell => spell.prepared === true) },
    ...(this.unpreparedRituals().length ? [{ key: 'rituals', title: 'Rituels disponibles', description: 'Rituels autorisés sans préparation.', spells: this.unpreparedRituals() }] : []),
    { key: 'other', title: 'Autres sorts du répertoire', description: 'Consulter les autres sources de sorts.',
      spells: this.displayed().filter(spell => !spell.known && spell.prepared !== true && !this.unpreparedRituals().includes(spell)) },
  ]);
  readonly editable = computed(() => !this.readonly() && this.view()?.editable === true && !this.busy() && !this.castSaving() && !this.pendingCast());
  readonly selections: Record<string, number> = {};
  spellKey(spell: PlayerSpell): string { return spell.key ?? `${spell.sourceType}:${spell.source}:${spell.spellId}`; }
  select(spell: PlayerSpell): void {
  this.castChoice.set('');
  const current = this.selection();

  if (current && this.spellKey(current) === this.spellKey(spell)) {
    this.selection.set(null);
  } else {
    this.selection.set(spell);
  }
}
  readonly schoolIcon = spellSchoolIcon;
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
  ngOnChanges(changes?: SimpleChanges): void {
    if (changes?.['quickSelection'] && this.quickSelection()) {
      this.selection.set(this.quickSelection()); this.castChoice.set('');
      this.openCastDialog(this.quickSelection()!);
    }
    if (this.accessToken() && this.view() && this.revision() > this.view()!.revision && !this.busy()) this.reload();
    if (this.prioritizePreparation() && this.mode() === 'portal' && this.view()?.editable && !this.readonly() && this.preparationAvailable() && !this.skipPreparationRedirect && !this.pendingCast()) {
      void this.router.navigate(['prepare-spells'], { relativeTo: this.route, replaceUrl: true });
    }
  }
  reload(): void {
    this.busy.set(true);
    this.api.get(this.accessToken()).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.busy.set(false))).subscribe({
      next: view => {
        this.view.set(view);
        if (!view.spellPreparationAllowed) this.skipPreparationRedirect = false;
        if (this.prioritizePreparation() && this.mode() === 'portal' && !this.skipPreparationRedirect
          && !this.readonly() && view.editable && this.preparationAvailable() && !this.pendingCast()) {
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

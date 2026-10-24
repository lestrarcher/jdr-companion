import { Component, DestroyRef, ElementRef, HostListener, Injector, OnInit, afterNextRender, computed, inject, signal, viewChild } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { HttpErrorResponse } from '@angular/common/http';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { PlayerSpellApiService, PlayerSpellPreparation, PlayerSpellView } from '@core/services/player-spell-api.service';
import { PlayerSpell } from '@core/services/character-session-state-api.service';
import { PlayerSpells } from '../player-portal/components/player-spells/player-spells';

@Component({
  selector: 'app-player-spell-preparation',
  imports: [RouterLink, PlayerSpells],
  templateUrl: './player-spell-preparation.html',
  styleUrl: './player-spell-preparation.scss',
})
export class PlayerSpellPreparationPage implements OnInit {
  private readonly api = inject(PlayerSpellApiService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  readonly accessToken = this.route.snapshot.paramMap.get('accessToken') ?? '';
  readonly view = signal<PlayerSpellView | null>(null);
  readonly draft = signal<Record<number, number[]>>({});
  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly conflict = signal(false);
  readonly error = signal('');
  readonly details = signal<PlayerSpell | null>(null);
  private readonly injector = inject(Injector);
  private readonly detailsPanel = viewChild<ElementRef<HTMLElement>>('detailsPanel');
  private detailsTrigger: HTMLElement | null = null;
  openDetails(spell: PlayerSpell, event: Event): void {
    this.detailsTrigger = event.currentTarget as HTMLElement;
    this.details.set(spell);
    afterNextRender(() => {
      const panel = this.detailsPanel()?.nativeElement;
      panel?.focus({ preventScroll: true });
      panel?.scrollIntoView({ block: 'start' });
    }, { injector: this.injector });
  }
  closeDetails(): void {
    this.details.set(null);
    afterNextRender(() => {
      if (this.detailsTrigger?.isConnected) {
        this.detailsTrigger.focus({ preventScroll: true });
        this.detailsTrigger.scrollIntoView({ block: 'center' });
      }
    }, { injector: this.injector });
  }
  readonly plans = computed(() => (this.view()?.preparations ?? []).map(plan => ({
    ...plan,
    groups: [...new Set(plan.available.map(spell => spell.level))].sort((a, b) => a - b)
      .map(level => ({ level, spells: plan.available.filter(spell => spell.level === level) })),
  })));
  readonly alwaysPrepared = computed(() => this.view()?.spells.filter(spell => spell.alwaysPrepared) ?? []);
  readonly selectedCount = computed(() => this.plans().reduce((total, plan) => total + this.selected(plan.policyId).length, 0));
  readonly excessCount = computed(() => this.plans().reduce((total, plan) => total + Math.max(0, this.selected(plan.policyId).length - plan.maximum), 0));
  readonly editable = computed(() => this.view()?.editable === true && this.view()?.spellPreparationAllowed === true
    && !this.loading() && !this.saving());
  readonly dirty = computed(() => this.plans().some(plan => {
    const selected = this.selected(plan.policyId);
    return selected.length !== plan.selected.length || selected.some(id => !plan.selected.includes(id));
  }));
  readonly canConfirm = computed(() => this.editable() && !this.conflict() && this.plans().length > 0
    && this.plans().every(plan => !plan.blocked && this.selected(plan.policyId).length <= plan.maximum));

  ngOnInit(): void { this.reload(); }
  selected(policyId: number): number[] { return this.draft()[policyId] ?? []; }
  toggle(plan: PlayerSpellPreparation, spellId: number): void {
    if (!this.editable() || plan.blocked || !plan.available.some(spell => spell.id === spellId)) return;
    const selected = this.selected(plan.policyId);
    this.draft.update(draft => ({ ...draft, [plan.policyId]: selected.includes(spellId)
      ? selected.filter(id => id !== spellId) : [...selected, spellId] }));
  }
  spellDetails(policyId: number, spellId: number): PlayerSpell | undefined {
    return this.view()?.spells.find(spell => spell.policyId === policyId && spell.spellId === spellId && spell.details);
  }
  reload(): void {
    if (this.loading() || this.saving()) return;
    if (this.dirty() && !window.confirm('Actualiser remplacera votre sélection non confirmée. Continuer ?')) return;
    this.loading.set(true); this.error.set('');
    this.api.get(this.accessToken).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: view => { this.accept(view); this.loading.set(false); },
      error: error => { this.loading.set(false); this.failed(error); },
    });
  }
  confirm(): void {
    if (!this.canConfirm()) return;
    const view = this.view()!;
    const selections = view.preparations.map(plan => ({ policyId: plan.policyId, spellIds: [...this.selected(plan.policyId)] }));
    this.saving.set(true); this.error.set('');
    this.api.confirmPreparation(this.accessToken, view.revision, selections).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: view => {
        this.accept(view); this.saving.set(false);
        void this.router.navigate(['..'], {
          relativeTo: this.route, queryParams: { tab: 'spells' }, replaceUrl: true,
        });
      },
      error: error => {
        this.saving.set(false);
        if (error.status === 409) this.conflict.set(true);
        this.failed(error);
      },
    });
  }
  private accept(view: PlayerSpellView): void {
    this.view.set(view);
    this.draft.set(Object.fromEntries(view.preparations.map(plan => [plan.policyId, [...plan.selected]])));
    this.conflict.set(false); this.details.set(null);
  }
  private failed(error: HttpErrorResponse): void {
    this.error.set(error.error?.message || error.error?.detail || 'Impossible de charger ou confirmer votre préparation.');
  }
  canLeavePreparation(): boolean {
    return !this.saving() && (!this.dirty() || window.confirm('Quitter sans confirmer votre préparation ?'));
  }
  @HostListener('window:beforeunload', ['$event'])
  beforeUnload(event: BeforeUnloadEvent): void {
    if (this.dirty() || this.saving()) event.preventDefault();
  }
}

import { AfterViewInit, Component, ElementRef, OnDestroy, computed, input, linkedSignal, output, signal, viewChild } from '@angular/core';
import { PlayerSpell } from '@core/services/character-session-state-api.service';
import { SpellCastCommand, SpellCastingAvailability, SpellMetamagic, availableSpellCastMethods, metamagicReasonLabel, spellHitPointEffect } from '@core/models/spell-cast.model';
import { CharacterResource } from '@core/models/character.model';
import { SpellTargetPicker } from '../spell-target-picker/spell-target-picker';

export type SpellCastSelection = Pick<SpellCastCommand, 'sourceKey' | 'method' | 'castingLevel' | 'targetCharacterIds' | 'hitPointRoll' | 'metamagic' | 'metamagics'>;
type CastOption = { source: PlayerSpell; method: SpellCastingAvailability['methods'][number]; key: string };

@Component({
  selector: 'app-spell-cast-dialog',
  imports: [SpellTargetPicker],
  templateUrl: './spell-cast-dialog.html',
  styleUrl: './spell-cast-dialog.scss',
})
export class SpellCastDialog implements AfterViewInit, OnDestroy {
  readonly spell = input.required<PlayerSpell>();
  readonly accessToken = input('');
  readonly sources = input.required<PlayerSpell[]>();
  readonly resources = input<readonly Pick<CharacterResource, 'id' | 'currentValue'>[]>([]);
  readonly school = input('');
  readonly saving = input(false);
  readonly blocked = input(false);
  readonly pending = input<{ command: SpellCastCommand; name: string; source: string } | null>(null);
  readonly uncertain = input(false);
  readonly error = input('');
  readonly success = input('');
  readonly confirmed = output<SpellCastSelection>();
  readonly closed = output<void>();
  readonly retried = output<void>();
  readonly dismissed = output<void>();
  readonly refreshed = output<void>();
  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');
  private readonly choice = signal<string | null>(null);
  private readonly sourceChoice = signal<string | null>(null);
  readonly higherLevelExpanded = signal(false);
  readonly hitPointEffect = computed(() => spellHitPointEffect(this.spell()));
  readonly maximumTargets = computed(() => this.hitPointEffect() === 'aid' ? 3 : 12);
  readonly targetIds = signal<number[]>([]);
  readonly hitPointRoll = signal<number | null>(null);
  readonly hitPointBonus = computed(() => {
    const level = this.selected()?.method.castingLevel;
    return this.hitPointEffect() === 'aid' ? (level === null || level === undefined ? null : 5 * (level - 1))
      : this.validRoll() ? this.hitPointRoll() : null;
  });
  readonly validRoll = computed(() => {
    const roll = this.hitPointRoll();
    return roll !== null && Number.isInteger(roll) && roll >= 2 && roll <= 20;
  });
  readonly canConfirm = computed(() => !this.locked() && !!this.selected()
    && this.selectedMetamagics().every(selected => this.metamagics().some(option => option.key === selected.key && option.status === 'available'))
    && this.combinedAffordable()
    && (!this.hitPointEffect() || this.targetIds().length >= 1 && this.targetIds().length <= this.maximumTargets()
      && (this.hitPointEffect() !== 'feast' || this.validRoll())));
  updateTargets(ids: number[]): void { if (!this.locked()) this.targetIds.set([...ids]); }
  updateRoll(value: string): void {
    if (!this.locked()) this.hitPointRoll.set(value.trim() === '' ? null : Number(value));
  }
  readonly displayOptions = computed<CastOption[]>(() => this.sources()
    .filter(source => source.spellId === this.spell().spellId && !!source.key && source.castingAvailability?.sourceKey === source.key)
    .flatMap(source => source.castingAvailability!.methods
      .map((method, index) => ({ source, method,
        key: JSON.stringify([source.key, source.policyId, method.kind, method.castingLevel, method.poolSlug, method.cost, index]) }))
      .filter(({ method }) => method.kind !== 'normal' && method.castingLevel !== null
        && (!this.hitPointEffect() || method.castingLevel >= (this.hitPointEffect() === 'aid' ? 2 : 6))
        // Only explicit server reasons establish structural inapplicability.
        // Missing/invalid counters (unknown) must remain visible and disabled.
        && !(method.status === 'unavailable' && [
          'inactive_pool', 'slot_level_too_low', 'not_a_ritual', 'ritual_cast_not_allowed',
        ].includes(method.reason ?? ''))
        && (method.kind !== 'standard-slot' && method.kind !== 'pact-slot'
          || source.level !== null && method.castingLevel >= source.level))));
  readonly options = computed(() => this.displayOptions().filter(option => availableSpellCastMethods(option.source).some(method => method === option.method)));
  readonly sourceChoices = computed(() => this.sources().filter(source => source.spellId === this.spell().spellId && !!source.key)
    .map(source => ({ source, available: this.options().some(option => option.source.key === source.key) })));
  readonly availableSources = computed(() => this.sourceChoices().filter(source => source.available));
  readonly activeSource = computed(() => this.availableSources().find(item => item.source.key === this.sourceChoice())?.source
    ?? (this.availableSources().length === 1 ? this.availableSources()[0].source
      : this.availableSources().length === 0 ? this.sourceChoices()[0]?.source : undefined));
  readonly visibleOptions = computed(() => this.displayOptions().filter(option => option.source.key === this.activeSource()?.key));
  readonly standardOptions = computed(() => this.visibleOptions().filter(option => option.method.kind === 'standard-slot'));
  readonly otherOptions = computed(() => this.visibleOptions().filter(option => option.method.kind !== 'standard-slot'));
  readonly selected = computed(() => this.choice() === null
    ? (this.options().length === 1 ? this.options()[0] : undefined)
    : this.options().find(option => option.key === this.choice()));
  readonly locked = computed(() => this.saving() || this.blocked() || !!this.pending() || !!this.success());
  readonly hasMetamagics = computed(() => !!this.selectedMetamagic() || this.sources().some(source => source.castingAvailability?.methods.some(method => method.metamagics?.length)));
  readonly metamagics = computed(() => this.selected()?.method.metamagics ?? []);
  private readonly metamagicSelection = linkedSignal({
    source: () => ({ spellId: this.spell().spellId, option: this.selected()
      ?? this.displayOptions().find(option => option.key === this.choice())
      ?? (this.choice() === null && this.displayOptions().length === 1 ? this.displayOptions()[0] : undefined) }),
    computation: (source, previous): (SpellMetamagic & { selectedLevel: number })[] => {
      const retained = previous?.value;
      if (!retained?.length || source.spellId !== previous!.source.spellId) return [];
      // A missing response is not a loss of eligibility. Keep the visible choice,
      // but canConfirm requires a fresh, available option before sending it.
      const level = source.option?.method.castingLevel;
      if (level != null && retained.some(option => level !== option.selectedLevel)) return [];
      const options = source.option?.method.metamagics;
      if (options === undefined) return retained;
      return retained.flatMap(selected => {
        const current = options.find(option => option.key === selected.key);
        return current?.status === 'available' || current?.status === 'unknown' ? [{ ...current, selectedLevel: selected.selectedLevel }] : [];
      });
    },
  });
  readonly selectedMetamagics = computed(() => this.metamagicSelection());
  readonly selectedMetamagic = computed(() => this.selectedMetamagics()[0]);
  readonly metamagicCost = computed(() => this.selectedMetamagics().reduce((sum, option) => sum + option.cost, 0));
  readonly combinedAffordable = computed(() => {
    if (this.selectedMetamagics().length < 2) return true; // Single-option affordability is supplied by the backend.
    const pools = this.resources().filter(pool => pool.id === 'sorcery-points');
    const method = this.selected()?.method;
    const total = this.metamagicCost() + (method?.poolSlug === 'sorcery-points' ? method.cost : 0);
    return pools.length === 1 && Number.isInteger(pools[0].currentValue) && pools[0].currentValue >= total;
  });
  isMetamagicSelected(key: string): boolean { return this.selectedMetamagics().some(option => option.key === key); }
  canSelectMetamagic(key: string): boolean {
    if (this.isMetamagicSelected(key)) return true;
    const selected = this.selectedMetamagics();
    return this.metamagics().some(option => option.key === key && option.status === 'available')
      && (selected.length === 0 || selected.length === 1 && (key === 'metamagic-empowered-spell' || selected[0].key === 'metamagic-empowered-spell'));
  }
  readonly metamagicReason = metamagicReasonLabel;
  selectMetamagic(key: string): void {
    if (this.locked() || !this.canSelectMetamagic(key)) return;
    this.metamagicSelection.set(this.isMetamagicSelected(key) ? this.selectedMetamagics().filter(option => option.key !== key)
      : [...this.selectedMetamagics(), { ...this.metamagics().find(option => option.key === key)!, selectedLevel: this.selected()!.method.castingLevel! }]);
  }
  readonly upcasting = computed(() => {
    const option = this.selected();
    return option && option.source.level !== null && option.method.castingLevel !== null && option.method.castingLevel > option.source.level;
  });
  readonly higherLevelText = computed(() => this.selected()?.source.details?.higherLevels?.trim()
    || 'Effet à plus haut niveau non renseigné');
  readonly higherLevelPreview = computed(() => this.higherLevelText().length > 180
    ? this.higherLevelText().slice(0, 180) + '…' : this.higherLevelText());
  ngAfterViewInit(): void { this.dialog().nativeElement.showModal(); }
  ngOnDestroy(): void { this.dialog().nativeElement.close(); }
  selectSource(key: string): void {
    if (this.locked() || !this.availableSources().some(item => item.source.key === key)) return;
    this.sourceChoice.set(key); this.choice.set('');
    const options = this.options().filter(option => option.source.key === key);
    if (options.length === 1) this.choice.set(options[0].key);
  }
  select(key: string): void {
    if (!this.locked() && this.options().some(option => option.key === key && option.source.key === this.activeSource()?.key)) this.choice.set(key);
  }
  close(): void { if (!this.saving()) { this.metamagicSelection.set([]); this.closed.emit(); } }
  cancel(event: Event): void { event.preventDefault(); this.close(); }
  confirm(): void {
    const option = this.selected();
    if (this.canConfirm() && option && option.method.kind !== 'normal' && option.method.castingLevel !== null) this.confirmed.emit({ sourceKey: option.source.key!,
      method: option.method.kind, castingLevel: option.method.castingLevel,
      ...(this.hitPointEffect() ? { targetCharacterIds: [...this.targetIds()] } : {}),
      ...(this.hitPointEffect() === 'feast' ? { hitPointRoll: this.hitPointRoll()! } : {}),
      ...(this.selectedMetamagics().length === 2 ? { metamagics: this.selectedMetamagics().map(option => option.key) }
        : this.selectedMetamagic() ? { metamagic: this.selectedMetamagic()!.key } : {}),
    });
  }
  levelLabel(level: number | null): string { return ['0', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX'][level ?? -1] ?? '?'; }
  remaining(option: CastOption): number | null {
    if (!option.method.poolSlug) return null;
    const matches = this.resources().filter(resource => resource.id === option.method.poolSlug);
    const value = matches[0]?.currentValue;
    return matches.length === 1 && Number.isInteger(value) && value >= 0 ? value : null;
  }
  itemRemaining(option: CastOption): number | null {
    const grant = option.source.itemGrant;
    return grant ? (grant.costMode === 'charges' ? grant.currentCharges : grant.remainingUses) : null;
  }
  isItemOption(option: CastOption): boolean {
    return option.method.kind === 'item-charges' || option.method.kind === 'item-limited';
  }
  unavailableLabel(option: CastOption): string {
    if (option.method.status === 'unknown') return 'État inconnu';
    if (option.method.reason === 'item_inactive') return 'Objet non équipé ou non harmonisé';
    if (option.method.reason === 'insufficient_item_uses') return 'Charges ou utilisations insuffisantes';
    if (option.method.reason === 'insufficient_resource') return 'Ressource insuffisante';
    return option.method.reason === 'exhausted' ? 'Épuisé' : 'Indisponible';
  }
  costLabel(option: CastOption): string {
    if (option.method.kind === 'item-charges') return `${option.method.cost} charge(s) de l’objet`;
    if (option.method.kind === 'item-limited') return `${option.method.cost} utilisation(s) de l’objet`;
    if (option.method.kind === 'arcanum') return '1 utilisation d’Arcanum (sans emplacement)';
    return option.method.kind === 'resource' ? `${option.method.cost} unité(s) de ressource de capacité`
      : option.method.cost === 0 ? 'sans emplacement' : `${option.method.cost} emplacement(s)`;
  }
  optionLabel(option: CastOption): string {
    const remaining = this.remaining(option);
    return `${option.source.source} · ${this.methodLabel(option.method.kind)} · niveau ${option.method.castingLevel}`
      + ` · coût : ${this.costLabel(option)}`
      + (this.isItemOption(option) ? ` · ${this.itemRemaining(option) ?? '?'} restant(s)` : option.method.poolSlug ? ` · ${remaining === null ? 'quantité restante inconnue' : remaining + ' restant(s)'}` : '')
      + (option.method.status === 'available' ? '' : ` · ${this.unavailableLabel(option)}`);
  }
  methodLabel(kind: string): string {
    return ({ arcanum: 'Arcanum mystique (sans emplacement)', resource: 'Utilisation de capacité (sans emplacement)', cantrip: 'Tour de magie', ritual: 'Rituel', 'standard-slot': 'Emplacement standard', 'pact-slot': 'Magie de pacte', 'item-charges': 'Charges d’objet', 'item-limited': 'Utilisations d’objet' } as Record<string, string>)[kind] ?? kind;
  }
}

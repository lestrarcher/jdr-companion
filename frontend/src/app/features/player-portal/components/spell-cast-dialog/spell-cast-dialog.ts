import { AfterViewInit, Component, ElementRef, OnDestroy, computed, input, output, signal, viewChild } from '@angular/core';
import { PlayerSpell } from '@core/services/character-session-state-api.service';
import { SpellCastCommand, SpellCastingAvailability, availableSpellCastMethods } from '@core/models/spell-cast.model';
import { CharacterResource } from '@core/models/character.model';

export type SpellCastSelection = Pick<SpellCastCommand, 'sourceKey' | 'method' | 'castingLevel'>;
type CastOption = { source: PlayerSpell; method: SpellCastingAvailability['methods'][number]; key: string };

@Component({
  selector: 'app-spell-cast-dialog',
  templateUrl: './spell-cast-dialog.html',
  styleUrl: './spell-cast-dialog.scss',
})
export class SpellCastDialog implements AfterViewInit, OnDestroy {
  readonly spell = input.required<PlayerSpell>();
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
  readonly displayOptions = computed<CastOption[]>(() => this.sources()
    .filter(source => source.spellId === this.spell().spellId && !!source.key && source.castingAvailability?.sourceKey === source.key)
    .flatMap(source => source.castingAvailability!.methods
      .map((method, index) => ({ source, method,
        key: JSON.stringify([source.key, source.policyId, method.kind, method.castingLevel, method.poolSlug, method.cost, index]) }))
      .filter(({ method }) => method.kind !== 'normal' && method.castingLevel !== null
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
  close(): void { if (!this.saving()) this.closed.emit(); }
  cancel(event: Event): void { event.preventDefault(); this.close(); }
  confirm(): void {
    const option = this.selected();
    if (!this.locked() && option && option.method.kind !== 'normal' && option.method.castingLevel !== null) this.confirmed.emit({ sourceKey: option.source.key!,
      method: option.method.kind, castingLevel: option.method.castingLevel });
  }
  levelLabel(level: number | null): string { return ['0', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX'][level ?? -1] ?? '?'; }
  remaining(option: CastOption): number | null {
    if (!option.method.poolSlug) return null;
    const matches = this.resources().filter(resource => resource.id === option.method.poolSlug);
    const value = matches[0]?.currentValue;
    return matches.length === 1 && Number.isInteger(value) && value >= 0 ? value : null;
  }
  unavailableLabel(option: CastOption): string {
    if (option.method.status === 'unknown') return 'État inconnu';
    return option.method.reason === 'exhausted' ? 'Épuisé' : 'Indisponible';
  }
  optionLabel(option: CastOption): string {
    const remaining = this.remaining(option);
    return `${option.source.source} · ${this.methodLabel(option.method.kind)} · niveau ${option.method.castingLevel}`
      + ` · coût : ${option.method.cost === 0 ? 'sans emplacement' : option.method.cost + ' emplacement(s)'}`
      + (option.method.poolSlug ? ` · ${remaining === null ? 'quantité restante inconnue' : remaining + ' restant(s)'}` : '')
      + (option.method.status === 'available' ? '' : ` · ${this.unavailableLabel(option)}`);
  }
  methodLabel(kind: string): string {
    return ({ cantrip: 'Tour de magie', ritual: 'Rituel', 'standard-slot': 'Emplacement standard', 'pact-slot': 'Magie de pacte' } as Record<string, string>)[kind] ?? kind;
  }
}

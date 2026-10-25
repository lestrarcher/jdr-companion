import { Component, computed, input, output } from '@angular/core';
import { PlayerSpell } from '@core/services/character-session-state-api.service';

/** A projection only: selecting a card never creates or executes a cast command. */
@Component({
  selector: 'app-spell-quick-list',
  standalone: true,
  template: `
    <div class="quickbar" role="group" aria-label="Répertoire rapide">
      @for (spell of entries(); track key(spell)) {
        <button type="button" class="quick-spell" [class.is-selected]="selectedKey() === key(spell)"
          [class.is-unavailable]="!available(spell)" [disabled]="disabled() || !available(spell)"
          [attr.aria-pressed]="selectedKey() === key(spell)"
          [attr.aria-label]="spell.name + ' · ' + spell.source + (!available(spell) ? ' · Indisponible' : '')"
          (click)="choose(spell)">
          <span class="spell-level">{{ spell.level === 0 ? 'Tour de magie' : 'Niv. ' + (spell.level ?? '?') }}</span>
          <span class="quick-name">{{ spell.name }}</span>
          <span class="quick-source">{{ spell.source }}</span>
          @if (spell.prepared) { <span class="prepared-dot" aria-label="Préparé"></span> }
          @if (!available(spell)) { <span class="quick-source">Indisponible</span> }
        </button>
      }
    </div>
  `,
  styles: `
    :host { display: block; min-width: 0; }
    .quickbar { display: flex; flex-wrap: wrap; gap: .5rem; padding: .5rem 0; }
    .quick-spell { box-sizing: border-box; position: relative; flex: 0 0 7rem; min-height: 44px;
      display: flex; flex-direction: column; align-items: center; gap: .25rem; padding: .6rem .4rem;
      border: 1px solid #716b8066; border-radius: .65rem; background: #232837; color: inherit; font: inherit; cursor: pointer; }
    .quick-spell:hover:not(:disabled) { background: #32384a; }
    .quick-spell.is-selected { border-color: #dfbf80; background: #3d3540; }
    .quick-spell:focus-visible { outline: 2px solid #e5c78e; outline-offset: 3px; }
    .quick-spell:disabled { cursor: default; opacity: .55; }
    .is-unavailable { filter: grayscale(1); }
    .spell-level { color: #e7d6b5; font-size: .65rem; }
    .quick-name { font-size: .75rem; line-height: 1.3; overflow-wrap: anywhere; }
    .quick-source { font-size: .6rem; overflow-wrap: anywhere; color: #c4becd; }
    .prepared-dot { position: absolute; left: .3rem; top: .3rem; width: .35rem; height: .35rem; border-radius: 50%; background: #9ed4b9; }
  `,
})
export class SpellQuickList {
  readonly spells = input.required<PlayerSpell[]>();
  readonly mode = input<'directory' | 'play'>('directory');
  readonly hideUnavailable = input(false);
  readonly disabled = input(false);
  readonly selectedKey = input<string | null>(null);
  readonly selected = output<PlayerSpell>();
  readonly entries = computed(() => this.spells()
    .filter(spell => (this.mode() === 'directory' || (spell.level !== null && spell.level >= 1))
      && (!this.hideUnavailable() || this.available(spell)))
    .sort((a, b) => (a.level ?? Infinity) - (b.level ?? Infinity) || a.name.localeCompare(b.name, 'fr')));
  key(spell: PlayerSpell): string { return spell.key ?? `${spell.sourceType}:${spell.source}:${spell.spellId}`; }
  available(spell: PlayerSpell): boolean {
    return !!spell.key && spell.castingAvailability?.sourceKey === spell.key
      && spell.castingAvailability.methods.some(method => method.status === 'available'
        && method.kind !== 'normal' && method.castingLevel !== null);
  }
  choose(spell: PlayerSpell): void {
    if (!this.disabled() && this.available(spell)) this.selected.emit(spell);
  }
}

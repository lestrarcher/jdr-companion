import { Component, computed, input, output } from '@angular/core';
import { PlayerSpell } from '@core/services/character-session-state-api.service';
import { availableSpellCastMethods } from '@core/models/spell-cast.model';

export const spellSchoolIcon = (school: string | null): string => ({
  abjuration: '⬡', conjuration: '◇', divination: '◉', enchantment: '❋',
  evocation: 'ϟ', illusion: '◈', necromancy: '☽', transmutation: '△',
} as Record<string, string>)[school ?? ''] ?? '✧';

/** Stable catalog IDs, not translated names. Add original art progressively. */
export const SPELL_ART_BY_ID: Readonly<Record<number, string>> = {
  219: '/spells/custom/misty-step.svg',
};

const KNOWN_SCHOOLS = new Set([
  'abjuration', 'conjuration', 'divination', 'enchantment',
  'evocation', 'illusion', 'necromancy', 'transmutation',
]);

export function spellArtwork(spell: Pick<PlayerSpell, 'spellId' | 'school'>): string | null {
  if (typeof spell.spellId === 'number' && SPELL_ART_BY_ID[spell.spellId]) {
    return SPELL_ART_BY_ID[spell.spellId];
  }
  return spell.school && KNOWN_SCHOOLS.has(spell.school)
    ? `/spells/schools/${spell.school}.svg` : null;
}

/** A projection only: selecting a card never creates or executes a cast command. */
@Component({
  selector: 'app-spell-quick-list',
  standalone: true,
  template: `
    <div class="quickbar" role="group" aria-label="Répertoire rapide">
      @for (spell of entries(); track key(spell)) {
        <button type="button" class="quick-spell" [class.is-selected]="selectedKey() === key(spell)"
          [attr.data-school]="spell.school" [title]="spell.name + ' · ' + spell.source"
          [class.is-unavailable]="!available(spell)" [disabled]="disabled() || !available(spell)"
          [attr.aria-pressed]="selectedKey() === key(spell)"
          [attr.aria-label]="spell.name + ' · ' + spell.source + (!available(spell) ? ' · Indisponible' : '')"
          (click)="choose(spell)">
          <span class="spell-icon" aria-hidden="true">
            @if (artwork(spell); as src) {
              <img class="spell-art" [src]="src" alt="" loading="lazy"
                (error)="onArtError($event, spell)" />
            }
            <span class="school-glyph">{{ schoolIcon(spell.school) }}</span>
          </span>
          <span class="spell-level" [attr.aria-label]="spell.level === 0 ? 'Tour de magie' : 'Niveau ' + (spell.level ?? '?')">{{ spell.level === 0 ? '∞' : spell.level ?? '?' }}</span>
          <span class="quick-name">{{ spell.name }}</span>
          @if (spell.prepared) { <span class="prepared-dot" aria-label="Préparé"></span> }
        </button>
      }
      @if (levelFilter() !== null && entries().length === 0) {
        <p role="status">{{ levelFilter() === 0 ? 'Aucun tour de magie connu.' : 'Aucun sort compatible avec ce niveau.' }}</p>
      }
    </div>
  `,
  styles: `
    :host { display: block; min-width: 0; color: var(--text-primary, #ece8df); }
    .quickbar { display: flex; flex-wrap: wrap; gap: .65rem; padding: .75rem .2rem .55rem; }
    .quick-spell { box-sizing: border-box; position: relative; flex: 0 0 5rem; width: 5rem; min-height: 6.4rem;
      display: flex; flex-direction: column; align-items: center; gap: .35rem; padding: .45rem .3rem;
      border: 1px solid #716b8066; border-radius: .65rem; background: #232837; color: inherit; font: inherit; cursor: pointer; }
    .quick-spell:hover:not(:disabled) { background: #32384a; }
    .quick-spell.is-selected { border-color: #dfbf80; background: #3d3540; box-shadow: inset 0 0 0 1px #dfbf8055; }
    .quick-spell:focus-visible { outline: 2px solid #e5c78e; outline-offset: 3px; }
    .quick-spell:disabled { cursor: default; opacity: .55; }
    .is-unavailable { filter: grayscale(1); }
    .spell-icon { position: relative; display: grid; place-items: center; width: 2.8rem; height: 2.8rem; font-size: 2rem; line-height: 1; color: #bba9e2; background: radial-gradient(circle, #7561a344, transparent 70%); }
    .spell-art { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; }
    .spell-art:not([hidden]) + .school-glyph { visibility: hidden; }
    .spell-art[hidden] { display: none; }
    [data-school=evocation] .spell-icon { color: #f2ae77; }
    [data-school=abjuration] .spell-icon { color: #91cbe0; }
    [data-school=necromancy] .spell-icon { color: #a6cc9a; }
    [data-school=divination] .spell-icon { color: #e8cf91; }
    .spell-level { position: absolute; top: .2rem; right: .35rem; color: #e7d6b5; font-size: .7rem; }
    .quick-name { font-size: .68rem; line-height: 1.25; overflow-wrap: anywhere; }
    .prepared-dot { position: absolute; left: .4rem; top: .4rem; width: .4rem; height: .4rem; border-radius: 50%; background: #9ed4b9; }
  `,
})
export class SpellQuickList {
  readonly schoolIcon = spellSchoolIcon;
  readonly artwork = spellArtwork;
  onArtError(event: Event, spell: PlayerSpell): void {
    const image = event.target;
    if (!(image instanceof HTMLImageElement)) return;
    const school = spell.school;
    const fallback = school && KNOWN_SCHOOLS.has(school) ? `/spells/schools/${school}.svg` : null;
    // Custom art failed: try the school's own icon once, then reveal the glyph.
    if (fallback && image.getAttribute('src') !== fallback) {
      image.src = fallback;
    } else {
      image.hidden = true;
    }
  }
  readonly spells = input.required<PlayerSpell[]>();
  readonly mode = input<'directory' | 'play'>('directory');
  readonly levelFilter = input<number | null>(null);
  readonly hideUnavailable = input(false);
  readonly disabled = input(false);
  readonly selectedKey = input<string | null>(null);
  readonly selected = output<PlayerSpell>();
  readonly entries = computed(() => this.spells()
    .filter(spell => (this.levelFilter() === null
      ? this.mode() === 'directory' || (spell.level !== null && spell.level >= 1)
      : spell.level !== null && (this.levelFilter() === 0 ? spell.level === 0 && spell.known
        : spell.level >= 1 && spell.level <= this.levelFilter()! && (spell.prepared || spell.alwaysPrepared || spell.known && !spell.inSpellbook)))
      && (!this.hideUnavailable() || this.available(spell)))
    .sort((a, b) => (a.level ?? Infinity) - (b.level ?? Infinity) || a.name.localeCompare(b.name, 'fr')));
  key(spell: PlayerSpell): string { return spell.key ?? `${spell.sourceType}:${spell.source}:${spell.spellId}`; }
  available(spell: PlayerSpell): boolean {
    return availableSpellCastMethods(spell).length > 0;
  }
  choose(spell: PlayerSpell): void {
    if (!this.disabled() && this.available(spell)) this.selected.emit(spell);
  }
}

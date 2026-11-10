import { ChangeDetectionStrategy, Component, inject, input, output, OnInit, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { MagicItemApiService, SpellOption } from '@core/services/magic-item-api.service';
import { PublicCharacterMagicItemApiService } from '@core/services/public-character-magic-item-api.service';

@Component({
  selector: 'app-pact-tome-initial',
  standalone: true,
  templateUrl: './pact-tome-initial.html',
  styleUrl: './pact-tome-initial.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PactTomeInitial implements OnInit {
  readonly bookId = input.required<number>();
  readonly accessToken = input.required<string>();
  readonly saved = output<void>();
  readonly cancelled = output<void>();
  private readonly magicItemApi = inject(PublicCharacterMagicItemApiService);
  private readonly spellSearchApi = inject(MagicItemApiService);
  protected readonly initialCantrips = signal<number[]>([]);
  protected readonly initialRituals = signal<number[]>([]);
  protected readonly initialOptions = signal<SpellOption[]>([]);
  protected readonly initialBusy = signal(false);
  protected readonly initialError = signal('');
  protected searchInitialSpells(query: string): void {
    this.spellSearchApi.searchSpells(query).subscribe({
      next: spells => this.initialOptions.set(spells.filter(s => s.level === 0 || (s.level === 1 && s.ritual))),
      error: () => this.initialError.set('Recherche impossible.'),
    });
  }
  ngOnInit(): void {
    this.initialCantrips.set([]); this.initialRituals.set([]); this.initialError.set('');
    this.searchInitialSpells('');
  }
  protected toggleInitialSpell(spell: SpellOption): void {
    const target = spell.level === 0 ? this.initialCantrips : this.initialRituals;
    const max = spell.level === 0 ? 3 : 2;
    target.update(ids => ids.includes(spell.id) ? ids.filter(id => id !== spell.id) : ids.length < max ? [...ids, spell.id] : ids);
  }
  protected saveInitialSpells(): void {
    const id = this.bookId();
    if (!id || this.initialBusy() || this.initialCantrips().length !== 3 || ![0, 2].includes(this.initialRituals().length)) return;
    this.initialBusy.set(true); this.initialError.set('');
    this.magicItemApi.saveInitialPactSpells(this.accessToken(), id, this.initialCantrips(), this.initialRituals()).subscribe({
      next: () => { this.initialBusy.set(false); this.saved.emit(); },
      error: (e: HttpErrorResponse) => { this.initialBusy.set(false); this.initialError.set(e.error?.message ?? 'Inscription impossible.'); },
    });
  }
}

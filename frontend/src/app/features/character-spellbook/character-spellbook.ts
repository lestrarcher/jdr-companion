import { Component, HostListener, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';

interface PolicyStatus { className: string; status: string; policyIds: number[] }
interface Spell { id: number; name: string; level: number }
interface Acquisition { id: number; spell_id: number; spell_name: string; decided_at: string; copy_details: { source: string; costCp: number; minutes: number; adjustment: string; reason: string } | null }
interface Options { learningStyle: string; available: Spell[]; acquisitions: Acquisition[] }
interface Quote { spellId: number; baseCostCp: number; baseMinutes: number }

@Component({
  selector: 'app-character-spellbook', imports: [FormsModule, RouterLink],
  templateUrl: './character-spellbook.html',
  styles: [`:host { display:block; padding:1.5rem; max-width:55rem; margin:auto } label { display:block; margin:1rem 0 } input, select, textarea { display:block; width:100%; padding:.6rem } input[type=checkbox] { display:inline; width:auto } fieldset { margin:1rem 0 } button { padding:.6rem 1rem } [role=alert] { color:#c33 }`],
})
export class CharacterSpellbook {
  private readonly http = inject(HttpClient);
  private readonly route = inject(ActivatedRoute);
  readonly campaignId = this.route.snapshot.paramMap.get('campaignId');
  private readonly base = `/api/characters/${this.route.snapshot.paramMap.get('characterId')}/spell-acquisitions`;
  readonly name = signal('');
  readonly policies = signal<PolicyStatus[]>([]);
  readonly spells = signal<Spell[]>([]);
  readonly copies = signal<Acquisition[]>([]);
  readonly quote = signal<Quote | null>(null);
  readonly busy = signal(false);
  readonly error = signal('');
  readonly success = signal('');
  readonly style = signal('');
  policyId = 0;
  spellId = 0;
  source = '';
  adjustment = 'none';
  reason = '';
  costCp = 0;
  minutes = 0;
  confirmed = false;

  canLeavePreparation(): boolean {
    return !this.busy() && (!this.source.trim() || window.confirm('Abandonner la copie non enregistrée ?'));
  }

  @HostListener('window:beforeunload', ['$event'])
  beforeUnload(event: BeforeUnloadEvent): void {
    if (this.source.trim() || this.busy()) event.preventDefault();
  }

  constructor() {
    this.busy.set(true);
    this.http.get<{ characterName: string; policies: PolicyStatus[] }>(this.base).subscribe({
      next: result => { this.name.set(result.characterName); this.policies.set(result.policies); this.busy.set(false); },
      error: error => this.failed(error),
    });
  }

  selectPolicy(): void {
    this.quote.set(null); this.confirmed = false; this.spells.set([]); this.copies.set([]); this.style.set('');
    if (!this.policyId) return;
    this.busy.set(true); this.error.set('');
    this.http.get<Options>(`${this.base}/${this.policyId}`).subscribe({
      next: options => {
        this.style.set(options.learningStyle);
        this.copies.set(options.acquisitions.filter(a => a.copy_details));
        this.spells.set(options.available.filter(spell => spell.level > 0 && !options.acquisitions.some(a => Number(a.spell_id) === spell.id)));
        this.busy.set(false);
      }, error: error => this.failed(error),
    });
  }

  selectSpell(): void {
    this.quote.set(null); this.confirmed = false;
    if (!this.spellId) return;
    this.busy.set(true); this.error.set('');
    this.http.get<Quote>(`${this.base}/${this.policyId}/copy/${this.spellId}`).subscribe({
      next: quote => {
        this.quote.set(quote); this.costCp = quote.baseCostCp; this.minutes = quote.baseMinutes;
        this.adjustment = 'none'; this.reason = ''; this.busy.set(false);
      }, error: error => this.failed(error),
    });
  }

  adjustmentChanged(): void {
    this.confirmed = false;
    const quote = this.quote();
    if (quote && this.adjustment === 'none') { this.costCp = quote.baseCostCp; this.minutes = quote.baseMinutes; }
  }

  submit(): void {
    const quote = this.quote();
    if (!quote || this.busy() || !this.confirmed || !this.source.trim() || (this.adjustment !== 'none' && !this.reason.trim())) return;
    this.busy.set(true); this.error.set(''); this.success.set('');
    this.http.post(`${this.base}/${this.policyId}/copy/${quote.spellId}`, {
      baseCostCp: quote.baseCostCp, baseMinutes: quote.baseMinutes,
      costCp: this.costCp, minutes: this.minutes, source: this.source,
      adjustment: this.adjustment, reason: this.reason, confirmed: this.confirmed,
    }).subscribe({
      next: () => {
        this.success.set('Copie enregistrée. Aucun débit du portefeuille.');
        this.source = ''; this.quote.set(null); this.confirmed = false; this.spellId = 0;
        this.selectPolicy();
      }, error: error => this.failed(error),
    });
  }

  private failed(error: HttpErrorResponse): void {
    this.error.set(error.error?.message || error.error?.detail || 'La demande a échoué. Les saisies sont conservées.');
    this.busy.set(false);
  }
}

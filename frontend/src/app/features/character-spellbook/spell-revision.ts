import { Component, HostListener, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

interface Acquisition {
  id: number;
  spell_id: number | null;
  spell_name: string;
  rule_id?: number | string | null;
}
interface RevisionOptions {
  acquisitions: Acquisition[];
  targets: { id: number; label: string }[];
  history: { id: number; reason: string; decided_at: string; override_rules: boolean; retired_count: number; created_count: number }[];
}
interface Spell {
  id: number;
  name: string;
  level?: number;
}
interface SpellChoice {
  ruleId: number;
  key: string;
  quota: string;
  remaining: number;
  available: { id: number }[];
}
interface PolicyOptions {
  formatVersion?: number;
  choices?: SpellChoice[];
}

@Component({
  selector: 'app-spell-revision',
  imports: [FormsModule, RouterLink],
  templateUrl: './spell-revision.html',
  styleUrl: './spell-revision.scss',
})
export class SpellRevision {
  private readonly http = inject(HttpClient);
  private readonly route = inject(ActivatedRoute);
  readonly campaignId = this.route.snapshot.paramMap.get('campaignId');
  readonly characterId = this.route.snapshot.paramMap.get('characterId');
  private readonly base = `/api/characters/${this.characterId}/spell-acquisitions`;

  readonly policies = signal<{ className: string; policyIds: number[] }[]>([]);
  readonly options = signal<RevisionOptions | null>(null);
  readonly selected = signal<Spell[]>([]);
  readonly results = signal<Spell[]>([]);
  readonly policyOptions = signal<PolicyOptions | null>(null);
  readonly rulesBySpell = signal<Record<number, number | null>>({});
  readonly busy = signal(false);
  readonly error = signal('');
  readonly success = signal('');

  policyId = 0;
  targetPolicyId = 0;
  reason = '';
  overrideRules = false;
  confirmed = false;
  query = '';

  private dirty = false;
  private loadedPolicyId = 0;
  private loadToken = 0;

  constructor() { this.loadPolicies(); }

  private loadPolicies(): void {
    this.busy.set(true);
    this.http.get<{ policies: { className: string; policyIds: number[] }[] }>(this.base)
      .subscribe({
        next: data => { this.policies.set(data.policies ?? []); this.busy.set(false); },
        error: e => this.fail(e),
      });
  }

  load(): void {
    if (this.dirty && !window.confirm('Abandonner la révision non enregistrée ?')) {
      this.policyId = this.loadedPolicyId;
      return;
    }
    const token = ++this.loadToken;
    this.options.set(null);
    this.policyOptions.set(null);
    this.rulesBySpell.set({});
    this.confirmed = false;
    this.selected.set([]);
    this.results.set([]);
    this.dirty = false;
    this.error.set('');
    if (!this.policyId) return;

    this.busy.set(true);
    this.http.get<RevisionOptions>(`${this.base}/${this.policyId}/revision`)
      .subscribe({
        next: data => {
          if (token !== this.loadToken) return;
          this.options.set(data);
          this.targetPolicyId = this.policyId;
          this.loadedPolicyId = this.policyId;
          this.selected.set(data.acquisitions
            .filter(a => a.spell_id !== null)
            .map(a => ({ id: Number(a.spell_id), name: a.spell_name })));
          this.loadTargetOptions(token);
        },
        error: e => { if (token === this.loadToken) this.fail(e); },
      });
  }

  targetChanged(): void {
    this.changed();
    this.policyOptions.set(null);
    this.rulesBySpell.set({});
    this.loadTargetOptions(++this.loadToken);
  }

  private loadTargetOptions(token: number): void {
    if (!this.targetPolicyId) { this.busy.set(false); return; }
    this.busy.set(true);
    this.error.set('');
    this.http.get<PolicyOptions>(`${this.base}/${this.targetPolicyId}`)
      .subscribe({
        next: data => {
          if (token !== this.loadToken) return;
          this.policyOptions.set(data);
          const prior = this.rulesBySpell();
          const existing = this.options()?.acquisitions ?? [];
          const choices: Record<number, number | null> = {};
          for (const spell of this.selected()) {
            const permitted = this.compatibleRules(spell.id, data);
            const original = existing.find(a => a.spell_id === spell.id);
            const recorded = original?.rule_id == null ? null : Number(original.rule_id);
            const preferred = prior[spell.id] ?? recorded;
            choices[spell.id] = preferred !== null && preferred !== undefined &&
              permitted.some(r => r.ruleId === preferred) ? preferred : null;
          }
          this.rulesBySpell.set(choices);
          this.busy.set(false);
        },
        error: e => { if (token === this.loadToken) this.fail(e); },
      });
  }

  private compatibleRules(spellId: number, options = this.policyOptions()): SpellChoice[] {
    return (options?.choices ?? []).filter(rule =>
      rule.available?.some(spell => Number(spell.id) === spellId));
  }

  rulesFor(spellId: number): SpellChoice[] {
    return this.compatibleRules(spellId);
  }

  selectedRule(spellId: number): number | null {
    return this.rulesBySpell()[spellId] ?? null;
  }

  setRule(spellId: number, ruleId: number | null): void {
    this.rulesBySpell.update(current => ({ ...current, [spellId]: ruleId }));
    this.changed();
  }

  ruleLabel(rule: SpellChoice): string {
    const names: Record<string, string> = {
      restricted: 'Écoles autorisées',
      unrestricted: 'Choix exceptionnel',
      cantrips: 'Tours de magie',
    };
    return `${names[rule.key] ?? rule.key} (${rule.quota})`;
  }

  changed(): void { this.dirty = true; this.confirmed = false; this.success.set(''); }

  search(): void {
    if (this.busy()) return;
    this.busy.set(true);
    this.error.set('');
    this.http.get<{ spells: Spell[] }>('/api/reference/custom/spells', {
      params: { q: this.query, pageSize: 25 },
    }).subscribe({
      next: data => { this.results.set(data.spells ?? []); this.busy.set(false); },
      error: e => this.fail(e),
    });
  }

  add(spell: Spell): void {
    if (this.selected().some(s => s.id === spell.id)) return;
    this.selected.update(items => [...items, spell]);
    this.rulesBySpell.update(current => ({ ...current, [spell.id]: null }));
    this.changed();
  }

  remove(id: number): void {
    this.selected.update(items => items.filter(s => s.id !== id));
    this.rulesBySpell.update(current => {
      const next = { ...current };
      delete next[id];
      return next;
    });
    this.changed();
  }

  get advanced(): boolean { return this.policyOptions()?.formatVersion === 2; }

  get rulesComplete(): boolean {
    if (!this.policyOptions()) return false;
    if (!this.advanced) return true;
    return this.selected().every(spell =>
      this.rulesFor(spell.id).some(rule => rule.ruleId === this.selectedRule(spell.id)));
  }

  save(): void {
    const options = this.options();
    if (!options || !this.policyOptions() || this.busy() ||
        !this.confirmed || !this.reason.trim() || !this.rulesComplete) return;

    const spellIds = this.selected().map(s => s.id);
    const payload: {
      targetPolicyId: number;
      spellIds: number[];
      expectedAcquisitionIds: number[];
      reason: string;
      overrideRules: boolean;
      confirmed: boolean;
      ruleChoices?: number[];
    } = {
      targetPolicyId: this.targetPolicyId,
      spellIds,
      expectedAcquisitionIds: options.acquisitions.map(a => Number(a.id)),
      reason: this.reason,
      overrideRules: this.overrideRules,
      confirmed: true,
    };
    if (this.advanced) payload.ruleChoices = spellIds.map(id => this.selectedRule(id)!);

    this.busy.set(true);
    this.error.set('');
    this.http.post(`${this.base}/${this.policyId}/revision`, payload).subscribe({
      next: () => {
        this.success.set('Révision enregistrée. L’historique antérieur est conservé.');
        this.policyId = this.targetPolicyId;
        this.reason = '';
        this.dirty = false;
        this.load();
      },
      error: e => this.fail(e),
    });
  }

  canLeavePreparation(): boolean {
    return !this.busy() && (!this.dirty || window.confirm('Abandonner la révision non enregistrée ?'));
  }

  @HostListener('window:beforeunload', ['$event'])
  beforeUnload(event: BeforeUnloadEvent): void {
    if (this.dirty || this.busy()) event.preventDefault();
  }

  private fail(e: HttpErrorResponse): void {
    this.error.set(e.error?.message || e.error?.detail || 'Échec de la révision. Les saisies sont conservées.');
    this.busy.set(false);
  }
}

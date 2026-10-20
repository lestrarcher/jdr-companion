import { Component, HostListener, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';
interface Spell { id: number; name: string }
interface Version { id: number; version: number; provenance: string; levels: { id: number; level: number; remaining: number }[]; available: Spell[] }
interface Grant {
  grantId: number; sourceName: string; className: string;
  originalSpell: Spell; currentSpell: Spell; expectedPrevious: number | null;
  activeRuleId: number; currentClassLevelId: number; versions: Version[];
}
interface History { id: number; grant_id: number; spell_name: string; version: number; decision_kind: string; reason: string; decided_at: string; effective_position: number }
interface Options { characterName: string; grants: Grant[]; history: History[] }
@Component({
  selector: 'app-spell-substitutions', imports: [FormsModule, RouterLink],
  templateUrl: './spell-substitutions.html',
  styleUrl: '../spell-policies/spell-policies.scss',
})
export class SpellSubstitutions {
  private readonly http = inject(HttpClient);
  private readonly route = inject(ActivatedRoute);
  readonly campaignId = this.route.snapshot.paramMap.get('campaignId');
  readonly base = `/api/characters/${this.route.snapshot.paramMap.get('characterId')}/spell-substitutions`;
  readonly options = signal<Options | null>(null);
  readonly busy = signal(false);
  readonly error = signal('');
  readonly success = signal('');
  grantId = 0; ruleId = 0; levelId = 0; spellId = 0;
  kind = 'substitution'; reason = ''; confirmed = false;
  get grant(): Grant | undefined { return this.options()?.grants.find(g => g.grantId === this.grantId); }
  get version(): Version | undefined { return this.grant?.versions.find(v => v.id === this.ruleId); }
  get activeVersion(): number { return this.grant?.versions.find(v => v.id === this.grant?.activeRuleId)?.version ?? 0; }
  decisionLabel(kind: string): string {
    return ({substitution:'Substitution',correction:'Correction MJ',revocation:'Révocation',transition:'Transition de version'} as Record<string,string>)[kind] ?? kind;
  }
  constructor() { this.load(); }
  load(): void {
    if (this.busy()) return;
    this.busy.set(true); this.confirmed = false;
    this.http.get<Options>(this.base).subscribe({
      next: data => { this.options.set(data); this.busy.set(false); this.selectGrant(); },
      error: e => { this.busy.set(false); this.error.set(e.status === 503 ? 'La migration des substitutions doit être autorisée et appliquée avant utilisation.' : 'Impossible de charger les substitutions.'); },
    });
  }
  selectGrant(): void {
    this.ruleId = this.grant?.activeRuleId ?? 0; this.kind = 'substitution';
    this.selectKind();
  }
  selectKind(): void {
    this.confirmed = false; this.spellId = 0; this.levelId = 0;
    if (this.kind !== 'transition') this.ruleId = this.grant?.activeRuleId ?? 0;
    if (this.kind !== 'substitution') this.levelId = this.grant?.currentClassLevelId ?? 0;
    if (this.kind === 'revocation') this.spellId = this.grant?.originalSpell.id ?? 0;
    if (this.kind === 'transition') this.spellId = this.grant?.currentSpell.id ?? 0;
  }
  save(): void {
    const grant = this.grant;
    if (this.busy() || !grant || !this.confirmed || !this.reason.trim() || !this.ruleId || !this.levelId || !this.spellId) return;
    this.busy.set(true); this.error.set(''); this.success.set('');
    this.http.post(this.base, { ruleId: this.ruleId, classLevelId: this.levelId, spellId: this.spellId, expectedPrevious: grant.expectedPrevious, reason: this.reason, confirmed: true, kind: this.kind }).subscribe({
      next: () => { this.busy.set(false); this.reason = ''; this.success.set('Décision enregistrée dans l’historique.'); this.load(); },
      error: e => {
        this.busy.set(false); this.confirmed = false;
        this.error.set(e.status === 409 ? 'Les choix ou le budget ont changé. Rechargez avant de confirmer à nouveau.' : 'Décision refusée. Vérifiez les critères, le niveau et les permissions.');
      },
    });
  }
  canLeavePreparation(): boolean { return !this.busy() && (!this.reason.trim() || window.confirm('Abandonner la décision non enregistrée ?')); }
  @HostListener('window:beforeunload', ['$event'])
  beforeUnload(event: BeforeUnloadEvent): void { if (this.busy() || this.reason.trim()) event.preventDefault(); }
}

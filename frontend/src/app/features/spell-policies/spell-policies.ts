import { Component, HostListener, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { JsonPipe } from '@angular/common';
import { PolicyGuide } from './policy-guide';

interface Reference { id: number; name: string; origin: string }
interface Version {
  id: number; origin: string; version: number; sourceName?: string;
  document: Record<string, unknown>;
}
interface Options {
  classes: Reference[]; subclasses: Reference[]; grants: Reference[]; spells?: Reference[];
  policies: Version[]; substitutions: Version[]; migrationRequired: boolean;
  example: Record<string, unknown>;
}
@Component({
  selector: 'app-spell-policies', imports: [FormsModule, JsonPipe, PolicyGuide],
  templateUrl: './spell-policies.html', styleUrl: './spell-policies.scss',
})
export class SpellPolicies {
  private readonly http = inject(HttpClient);
  readonly options = signal<Options | null>(null);
  readonly busy = signal(false);
  readonly error = signal('');
  readonly success = signal('');
  kind: 'policy' | 'substitution' = 'policy';
  preset = 'artificer';
  presetClassId = 0;
  tasha = false;
  document = '';
  private saved = '';
  get references(): Record<string, any[]> {
    const o=this.options();
    return { classes:o?.classes ?? [], subclasses:o?.subclasses ?? [], grants:o?.grants ?? [], policies:o?.policies ?? [],spells:o?.spells ?? [] };
  }
  newDocument(): void {
    if (!this.canLeavePreparation()) return;
    this.document=JSON.stringify(this.kind==='policy' ? this.options()?.example : {
      grantId:null,version:1,budgetKey:'substitutions',schools:[],opportunities:Array(20).fill(0),classIds:[],provenance:'',
    },null,2);
  }
  constructor() { this.load(); }
  preview(): void {
    if (!this.presetClassId || !this.canLeavePreparation()) return;
    this.busy.set(true); this.error.set('');
    this.http.post<{document:Record<string,unknown>}>('/api/reference/custom/spell-policies/preview',{sourceId:this.presetClassId,preset:this.preset,tasha:this.tasha}).subscribe({
      next: result => { this.kind='policy';this.document=JSON.stringify(result.document,null,2);this.busy.set(false); },
      error: () => { this.error.set('Préréglage refusé. Sélectionnez une classe personnelle et une option compatible.');this.busy.set(false); },
    });
  }
  private load(): void {
    this.busy.set(true);
    this.http.get<Options>('/api/reference/custom/spell-policies').subscribe({
      next: options => {
        this.options.set(options);
        if (!this.document) this.document = this.saved = JSON.stringify(options.example, null, 2);
        this.busy.set(false);
      },
      error: () => { this.error.set('Impossible de charger les politiques.'); this.busy.set(false); },
    });
  }
  canLeavePreparation(): boolean {
    return !this.busy() && (this.document === this.saved || window.confirm('Abandonner les modifications non enregistrées ?'));
  }
  @HostListener('window:beforeunload', ['$event'])
  beforeUnload(event: BeforeUnloadEvent): void {
    if (this.busy() || this.document !== this.saved) { event.preventDefault(); event.returnValue = ''; }
  }
  edit(version: Version, kind: 'policy' | 'substitution'): void {
    if (version.origin !== 'CUSTOM' || !this.canLeavePreparation()) return;
    const document = structuredClone(version.document);
    if (kind === 'policy') {
      const policy = document['policy'] as Record<string, unknown>;
      policy['version'] = version.version + 1;
    } else document['version'] = version.version + 1;
    this.kind = kind; this.document = JSON.stringify(document, null, 2);
    this.error.set(''); this.success.set('');
  }
  save(): void {
    if (this.busy() || !this.options() || this.options()?.migrationRequired) return;
    this.error.set(''); this.success.set('');
    let payload: unknown;
    try {
      payload = JSON.parse(this.document);
      if (!payload || Array.isArray(payload) || typeof payload !== 'object') throw new Error();
    } catch { this.error.set('Un objet JSON valide est requis.'); return; }
    this.busy.set(true);
    const suffix = this.kind === 'substitution' ? '/substitution-rules' : '';
    this.http.post('/api/reference/custom/spell-policies' + suffix, payload).subscribe({
      next: () => {
        this.saved = this.document;
        this.success.set('Version enregistrée. Les personnages existants restent inchangés.');
        this.load();
      },
      error: response => {
        this.error.set(typeof response.error?.message === 'string' ? response.error.message : 'Enregistrement refusé. Vérifiez les références, les droits et le numéro de version.');
        this.busy.set(false);
      },
    });
  }
}

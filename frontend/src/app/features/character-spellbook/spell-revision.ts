import { Component, HostListener, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

interface RevisionOptions {
  acquisitions: { id: number; spell_id: number | null; spell_name: string }[];
  targets: { id: number; label: string }[];
  history: { id: number; reason: string; decided_at: string; override_rules: boolean; retired_count: number; created_count: number }[];
}
@Component({
  selector: 'app-spell-revision', imports: [FormsModule, RouterLink], templateUrl: './spell-revision.html',
  styles: [`:host { display:block; max-width:55rem; padding:1.5rem; margin:auto } label { display:block; margin:1rem 0 } select, textarea { display:block; width:100%; padding:.6rem } button { margin:.3rem; padding:.5rem } [role=alert] { color:#c33 }`],
})
export class SpellRevision {
  private readonly http=inject(HttpClient);
  private readonly route=inject(ActivatedRoute);
  readonly campaignId=this.route.snapshot.paramMap.get('campaignId');
  readonly characterId=this.route.snapshot.paramMap.get('characterId');
  private readonly base=`/api/characters/${this.characterId}/spell-acquisitions`;
  readonly policies=signal<{ className: string; policyIds: number[] }[]>([]);
  readonly options=signal<RevisionOptions | null>(null);
  readonly selected=signal<{ id: number; name: string }[]>([]);
  readonly results=signal<{ id: number; name: string; level: number }[]>([]);
  readonly busy=signal(false); readonly error=signal(''); readonly success=signal('');
  policyId=0; targetPolicyId=0; reason=''; overrideRules=false; confirmed=false; query='';
  private dirty=false;
  private loadedPolicyId=0;
  constructor() { this.loadPolicies(); }
  private loadPolicies(): void {
    this.busy.set(true);
    this.http.get<{ policies: { className: string; policyIds: number[] }[] }>(this.base).subscribe({ next: data=>{ this.policies.set(data.policies); this.busy.set(false); }, error: e=>this.fail(e) });
  }
  load(): void {
    if (this.dirty && !window.confirm('Abandonner la révision non enregistrée ?')) { this.policyId=this.loadedPolicyId; return; }
    this.options.set(null); this.confirmed=false; this.selected.set([]); this.results.set([]); this.dirty=false;
    if (!this.policyId) return;
    this.busy.set(true); this.error.set('');
    this.http.get<RevisionOptions>(`${this.base}/${this.policyId}/revision`).subscribe({ next: data=>{
      this.options.set(data); this.targetPolicyId=this.policyId;
      this.loadedPolicyId=this.policyId;
      this.selected.set(data.acquisitions.filter(a=>a.spell_id!==null).map(a=>({ id:Number(a.spell_id),name:a.spell_name })));
      this.busy.set(false);
    },error:e=>this.fail(e) });
  }
  changed(): void { this.dirty=true; this.confirmed=false; }
  search(): void {
    if (this.busy()) return;
    this.busy.set(true); this.error.set('');
    this.http.get<{ spells: { id:number; name:string; level:number }[] }>('/api/reference/custom/spells',{ params:{ q:this.query,pageSize:25 } }).subscribe({ next:data=>{ this.results.set(data.spells); this.busy.set(false); }, error:e=>this.fail(e) });
  }
  add(spell:{id:number;name:string}):void { if (!this.selected().some(s=>s.id===spell.id)) { this.selected.update(items=>[...items,spell]); this.changed(); } }
  remove(id:number):void { this.selected.update(items=>items.filter(s=>s.id!==id)); this.changed(); }
  save():void {
    const options=this.options();
    if (!options || this.busy() || !this.confirmed || !this.reason.trim()) return;
    this.busy.set(true); this.error.set('');
    this.http.post(`${this.base}/${this.policyId}/revision`,{ targetPolicyId:this.targetPolicyId,spellIds:this.selected().map(s=>s.id),expectedAcquisitionIds:options.acquisitions.map(a=>Number(a.id)),reason:this.reason,overrideRules:this.overrideRules,confirmed:true }).subscribe({
      next:()=>{ this.success.set('Révision enregistrée. L’historique antérieur est conservé.'); this.policyId=this.targetPolicyId; this.reason=''; this.dirty=false; this.load(); },error:e=>this.fail(e),
    });
  }
  canLeavePreparation():boolean { return !this.busy() && (!this.dirty || window.confirm('Abandonner la révision non enregistrée ?')); }
  @HostListener('window:beforeunload',['$event']) beforeUnload(event:BeforeUnloadEvent):void { if(this.dirty || this.busy()) event.preventDefault(); }
  private fail(e:HttpErrorResponse):void { this.error.set(e.error?.message || e.error?.detail || 'Échec de la révision. Les saisies sont conservées.'); this.busy.set(false); }
}

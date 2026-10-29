import { Component, effect, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';

// The server remains authoritative; the guide preserves the complete advanced document.
@Component({
  selector: 'app-policy-guide', imports: [FormsModule],
  template: `
    <button type="button" [disabled]="disabled()" (click)="open()">Ouvrir le formulaire guidé depuis le document</button>
    @if (error()) { <p role="alert">{{ error() }}</p> }
    @if (draft; as d) {
      <fieldset [disabled]="disabled()">
      @if (d.policy) {
        <label>Source <select [(ngModel)]="d.sourceType" (ngModelChange)="d.sourceId=null; change()"><option value="class">Classe</option><option value="subclass">Sous-classe</option></select></label>
        <label>Référence personnelle <select [(ngModel)]="d.sourceId" (ngModelChange)="change()"><option [ngValue]="null">Choisir</option>
          @for (r of references()[d.sourceType === 'class' ? 'classes' : 'subclasses'] || []; track r.id) {
            @if (r.origin === 'CUSTOM') { <option [ngValue]="r.id">{{ r.name }}</option> }
          }
        </select></label>
        <label>Identité de politique <input [(ngModel)]="d.policy.key" (ngModelChange)="change()"></label>
        <label>Version <input type="number" min="1" [(ngModel)]="d.policy.version" (ngModelChange)="change()"></label>
        <label>Caractéristique <select [(ngModel)]="d.policy.ability" (ngModelChange)="change()">@for (a of abilities; track a) { <option [value]="a">{{ a }}</option> }</select></label>
        <label>Fonctionnement <select [(ngModel)]="d.policy.learningStyle" (ngModelChange)="change()"><option value="known">Sorts connus</option><option value="prepared">Préparation depuis une liste</option><option value="spellbook">Grimoire</option></select></label>
        <label>Provenance <textarea [(ngModel)]="d.policy.provenance" (ngModelChange)="change()"></textarea></label>
        <label>Politique de base figée <select [(ngModel)]="d.basePolicyId" (ngModelChange)="change()"><option [ngValue]="null">Aucune composition</option>@for (r of references()['policies'] || []; track r.id) { <option [ngValue]="r.id">{{ r.sourceName }} — version {{ r.version }}</option> }</select></label>
        <div class="table"><table><caption>Quotas par niveau de classe</caption><thead><tr><th>Niveau</th>@for (key of levelFields; track key) { <th>{{ labels[key] }}</th> }</tr></thead><tbody>
        @for (level of d.policy.levels; track $index; let n=$index) {
          <tr><th>{{ n+1 }}</th>@for (key of levelFields; track key) { <td><input type="number" min="0" [attr.aria-label]="labels[key]+' niveau '+(n+1)" [(ngModel)]="level[key]" (ngModelChange)="change()"></td> }</tr>
        }</tbody></table></div>
        <h3>Règles de choix</h3>
        @for (rule of d.rules || []; track $index; let i=$index) {
          <fieldset><legend>Choix {{ i+1 }}</legend>
          <label>Identité <input [(ngModel)]="rule.key" (ngModelChange)="change()"></label>
          @for (key of classFields; track key) { <label>{{ labels[key] }} <select [(ngModel)]="rule[key]" (ngModelChange)="change()"><option [ngValue]="null">Non renseigné</option>@for (r of references()['classes'] || []; track r.id) { <option [ngValue]="r.id">{{ r.name }}</option> }</select></label> }
          <label>Sort imposé <select [(ngModel)]="rule.requiredSpellId" (ngModelChange)="change()"><option [ngValue]="null">Aucun</option>@for (r of references()['spells'] || []; track r.id) { <option [ngValue]="r.id">{{ r.name }}</option> }</select></label>
          <label>Budget <select [(ngModel)]="rule.parameters.quota" (ngModelChange)="change()">@for (q of quotas; track q) { <option [value]="q">{{ q }}</option> }</select></label>
          <label>Résultat <select [(ngModel)]="rule.parameters.result" (ngModelChange)="change()"><option value="known">Connu</option><option value="spellbook">Inscrit au grimoire</option><option value="cast">Voie particulière</option></select></label>
          <label>Groupe de remplacement <input [(ngModel)]="rule.parameters.replacementBudget" (ngModelChange)="change()"></label>
          <label>Niveau minimum du sort <input type="number" min="0" max="9" [(ngModel)]="rule.parameters.minimumSpellLevel" (ngModelChange)="change()"></label>
          <label>Niveau minimum lors du remplacement <input type="number" min="0" max="9" [(ngModel)]="rule.parameters.replacementMinimumSpellLevel" (ngModelChange)="change()"></label>
          <label><input type="checkbox" [(ngModel)]="rule.parameters.allClassLists" (ngModelChange)="change()"> Toutes les listes autorisées</label>
          <label><input type="checkbox" [(ngModel)]="rule.parameters.optionalReplacement" (ngModelChange)="change()"> Remplacement facultatif, confirmation requise</label>
          @for (key of schoolFields; track key) { <label>{{ labels[key] }} <select multiple [(ngModel)]="rule.parameters[key]" (ngModelChange)="change()">@for (s of schools; track s) { <option [value]="s">{{ s }}</option> }</select></label> }
          <table><thead><tr><th>Niveau</th><th>Opportunités</th><th>Niveau maximal du sort</th><th>Remplacements</th></tr></thead><tbody>
          @for (n of indices; track n) { <tr><th>{{ n+1 }}</th>@for (key of choiceTables; track key) { <td><input type="number" min="0" [attr.aria-label]="labels[key]+' niveau '+(n+1)" [(ngModel)]="rule.parameters[key][n]" (ngModelChange)="change()"></td> }</tr> }
          </tbody></table>
          <button type="button" (click)="d.rules.splice(i,1);change()">Retirer ce choix du brouillon</button></fieldset>
        }
        <button type="button" (click)="addRule()">Ajouter une règle de choix</button>
      } @else {
        <label>Attribution personnelle <select [(ngModel)]="d.grantId" (ngModelChange)="change()">@for (r of references()['grants'] || []; track r.id) { @if (r.origin === 'CUSTOM') { <option [ngValue]="r.id">{{ r.name }}</option> } }</select></label>
        <label>Version <input type="number" min="1" [(ngModel)]="d.version" (ngModelChange)="change()"></label>
        <label>Budget partagé <input [(ngModel)]="d.budgetKey" (ngModelChange)="change()"></label>
        <label>Écoles autorisées <select multiple [(ngModel)]="d.schools" (ngModelChange)="change()">@for (s of schools; track s) { <option [value]="s">{{ s }}</option> }</select></label>
        <label>Listes autorisées <select multiple [(ngModel)]="d.classIds" (ngModelChange)="change()">@for (r of references()['classes'] || []; track r.id) { <option [ngValue]="r.id">{{ r.name }}</option> }</select></label>
        <label>Provenance <textarea [(ngModel)]="d.provenance" (ngModelChange)="change()"></textarea></label>
        @for (n of indices; track n) { <label>Niveau {{ n+1 }} : opportunités <input type="number" min="0" max="20" [(ngModel)]="d.opportunities[n]" (ngModelChange)="change()"></label> }
      }
      <p>Le document avancé est actualisé. Enregistrez la version avec le formulaire principal.</p>
      </fieldset>
    }`,
  styles: [`fieldset { display:grid; gap:.7rem; margin:1rem 0; border:1px solid #887047 } label { display:grid; gap:.3rem } input,select,textarea { color:inherit; background:#25232d; border:1px solid #887047; padding:.4rem } .table { overflow:auto } table input { max-width:6rem }`],
})
export class PolicyGuide {
  readonly document = input('');
  readonly disabled = input(false);
  // Documents are intentionally schema-shaped; the strict backend validator is shared with the advanced editor.
  readonly references = input<Record<string, any[]>>({});
  readonly changed = output<string>();
  readonly error = signal('');
  draft: any = null;
  private emitted = '';
  constructor() { effect(() => { if (this.document() !== this.emitted) this.draft = null; }); }
  readonly indices = Array.from({ length: 20 }, (_, n) => n);
  readonly abilities = ['strength','dexterity','constitution','intelligence','wisdom','charisma'];
  readonly schools = ['abjuration','conjuration','divination','enchantment','evocation','illusion','necromancy','transmutation'];
  readonly quotas = ['known','cantrips','spellbook','free'];
  readonly classFields = ['listClassId','replacementListClassId','castingClassId'];
  readonly schoolFields = ['schools','replacementSchools'];
  readonly choiceTables = ['opportunities','maxSpellLevels','replacements'];
  readonly levelFields = ['maxSpellLevel','cantrips','known','bookAdditions','replacements','preparationBase'];
  readonly labels: Record<string,string> = { maxSpellLevel:'Niveau maximal',cantrips:'Mineurs',known:'Connus',bookAdditions:'Ajouts au grimoire',replacements:'Remplacements',preparationBase:'Base préparation',listClassId:'Liste de sélection',replacementListClassId:'Liste de remplacement',castingClassId:'Classe de lancement',schools:'Écoles de sélection',replacementSchools:'Écoles de remplacement',opportunities:'Opportunités',maxSpellLevels:'Niveau maximal du sort' };
  open(): void {
    try {
      const d = JSON.parse(this.document());
      if (!d || Array.isArray(d) || typeof d !== 'object' || (!Array.isArray(d.policy?.levels) && !Array.isArray(d.opportunities))) throw new Error();
      if (d.policy) {
        if (d.policy.levels.length!==20 || d.policy.levels.some((v:unknown)=>!v || typeof v!=='object' || Array.isArray(v))) throw new Error();
        if (d.rules!==undefined && (!Array.isArray(d.rules) || d.rules.some((r:any)=>!r?.parameters || this.choiceTables.some(k=>!Array.isArray(r.parameters[k]) || r.parameters[k].length!==20) || this.schoolFields.some(k=>!Array.isArray(r.parameters[k]))))) throw new Error();
      } else if (d.opportunities.length!==20 || !Array.isArray(d.schools) || !Array.isArray(d.classIds)) throw new Error();
      this.draft = d; this.error.set('');
    } catch { this.error.set('Chargez d’abord un document de politique ou substitution valide.'); this.draft = null; }
  }
  change(): void { this.emitted = JSON.stringify(this.draft, null, 2); this.changed.emit(this.emitted); }
  addRule(): void {
    this.draft.rules ??= [];
    this.draft.rules.push({ key: 'nouveau-choix', listClassId:null, replacementListClassId:null, castingClassId:null, requiredSpellId:null, parameters: {
      opportunities:Array(20).fill(0),maxSpellLevels:Array(20).fill(0),minimumSpellLevel:0,replacementMinimumSpellLevel:0,schools:[],replacementSchools:[],quota:'cantrips',result:'known',replacementBudget:'mineurs',replacements:Array(20).fill(0),allClassLists:false,optionalReplacement:false,
    } }); this.change();
  }
}

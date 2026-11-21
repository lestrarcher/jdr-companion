import { Component, effect, inject, input, output, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { REFERENCE_READ_ONLY } from '@core/services/reference-editor-context';

interface Weapon {
  id?: number; name: string; attackType: string; attackBonus: number | null;
  damageComponents: { dice: string; type: string | null }[]; description: string;
}
interface Stats {
  type?: string; size?: string; armorClass: number; hitPoints: number; hitDice?: string | null;
  abilities: Record<string, number>; speed: Record<string, number>; senses?: Record<string, string | number>;
  [key: string]: unknown;
}
export interface BeastProfile {
  id?: number; name: string | null; slug: string; challengeRating: string | null;
  sourceUrl: string | null; statBlock: Stats | null; habitats: string[];
  naturalWeapons: Weapon[]; imageFilename?: string | null;
}

@Component({
  selector: 'app-beast-profile-editor', standalone: true, imports: [FormsModule],
  templateUrl: './beast-profile-editor.html', styleUrl: './beast-profile-editor.scss',
})
export class BeastProfileEditor {
  readonly beastId = input<number | null>(null);
  readonly saved = output<BeastProfile>();
  readonly deleted = output<number>();
  private readonly http = inject(HttpClient);
  protected readonly readOnly = inject(REFERENCE_READ_ONLY);
  protected readonly model = signal<BeastProfile | null>(null);
  protected readonly loading = signal(false);
  protected readonly busy = signal(false);
  protected readonly message = signal('');
  protected readonly confirmDelete = signal(false);
  protected readonly habitats = ['aquatique','arctique','collines','desert','foret','littoral','marais','montagne','plaine','outreterre'];
  protected readonly abilities = [{key:'str',label:'Force'},{key:'dex',label:'Dextérité'},{key:'con',label:'Constitution'},{key:'int',label:'Intelligence'},{key:'wis',label:'Sagesse'},{key:'cha',label:'Charisme'}];
  protected readonly speeds = [{key:'walk',label:'Marche'},{key:'swim',label:'Nage'},{key:'fly',label:'Vol'},{key:'climb',label:'Escalade'},{key:'burrow',label:'Fouissement'}];
  protected senses: {key: string; value: string | number}[] = [];

  constructor() {
    effect(onCleanup => {
      const id = this.beastId();
      this.message.set(''); this.confirmDelete.set(false); this.model.set(null);
      if (id === null) {
        this.adopt({name:'',slug:'',challengeRating:'0',sourceUrl:null,statBlock:null,habitats:[],naturalWeapons:[]});
        this.loading.set(false); return;
      }
      this.loading.set(true);
      const subscription = this.http.get<{beast: BeastProfile}>(`/api/dnd/beast-features/${id}`).subscribe({
        next: result => { this.adopt(result.beast); this.loading.set(false); },
        error: () => { this.loading.set(false); this.message.set('Impossible de charger la fiche.'); },
      });
      onCleanup(() => subscription.unsubscribe());
    });
  }
  private adopt(beast: BeastProfile): void {
    this.model.set({...beast, name: beast.name ?? beast.slug});
    this.senses = Object.entries(beast.statBlock?.senses ?? {}).map(([key,value]) => ({key,value}));
  }
  protected initializeStats(): void {
    const beast = this.model(); if (!beast) return;
    beast.statBlock = {type:'beast',size:'Medium',armorClass:10,hitPoints:1,hitDice:'1d4',abilities:{str:10,dex:10,con:10,int:10,wis:10,cha:10},speed:{walk:9},senses:{},actions:[],traits:[]};
    this.model.set({...beast});
  }
  protected toggleHabitat(value: string, checked: boolean): void {
    const beast = this.model(); if (!beast) return;
    beast.habitats = checked ? [...beast.habitats,value] : beast.habitats.filter(h => h !== value);
  }
  protected addWeapon(): void {
    this.model()?.naturalWeapons.push({name:'',attackType:'melee',attackBonus:0,damageComponents:[],description:''});
  }
  protected save(): void {
    const beast = this.model(); if (!beast || this.readOnly || this.busy()) return;
    const {name, slug, challengeRating, sourceUrl, statBlock, habitats, naturalWeapons} = beast;
    const data = structuredClone({name, slug, challengeRating, sourceUrl, statBlock, habitats, naturalWeapons});
    data.sourceUrl = data.sourceUrl?.trim() || null;
    data.challengeRating = data.challengeRating?.trim() || null;
    if (data.statBlock) {
      data.statBlock.hitDice = data.statBlock.hitDice?.trim() || null;
      const keys = this.senses.map(s => s.key.trim());
      if (keys.some(k => !k) || new Set(keys).size !== keys.length) { this.message.set('Chaque sens doit avoir un nom unique.'); return; }
      data.statBlock.senses = Object.fromEntries(this.senses.map(s => [s.key.trim(), s.key.trim() === 'passive_perception' && /^\d+$/.test(String(s.value)) ? Number(s.value) : s.value]));
    }
    this.busy.set(true); this.message.set('');
    const request = beast.id
      ? this.http.put<{beast: BeastProfile}>(`/api/dnd/beast-features/${beast.id}/profile`, data)
      : this.http.post<{beast: BeastProfile}>('/api/dnd/beast-features', data);
    request.subscribe({
      next: result => { this.adopt(result.beast); this.busy.set(false); this.message.set('Fiche enregistrée.'); this.saved.emit(result.beast); },
      error: e => { this.busy.set(false); this.message.set(e.error?.detail ?? e.error?.message ?? 'Enregistrement impossible. Vérifiez les valeurs.'); },
    });
  }
  protected delete(): void {
    const id = this.model()?.id;
    if (!id || this.readOnly || this.busy()) return;
    this.busy.set(true);
    this.http.delete(`/api/dnd/beast-features/${id}`).subscribe({
      next: () => { this.busy.set(false); this.deleted.emit(id); },
      error: e => { this.busy.set(false); this.confirmDelete.set(false); this.message.set(e.error?.detail ?? e.error?.message ?? 'Suppression refusée : vérifiez les observations et transformations.'); },
    });
  }
}

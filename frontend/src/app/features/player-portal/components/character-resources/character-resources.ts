import {
  Component,
  effect,
  inject,
  computed,
  input,
  TemplateRef,
  signal,
  output,
  OnInit,
  DestroyRef,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { NgTemplateOutlet } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

import { CharacterResource } from '@core/models/character.model';
import { CharacterStateService } from '@core/services/character-state.service';

@Component({
  selector: 'app-character-resources',
  imports: [FormsModule, NgTemplateOutlet],
  templateUrl: './character-resources.html',
  styleUrl: './character-resources.scss',
})
export class CharacterResources implements OnInit {
  readonly accessToken = input('');
  readonly druidLevel = input(0);
  readonly moonCircle = input(false);
  readonly wildShapeRestRefresh = input(0);
  constructor() {
    effect(() => {
      if (this.wildShapeRestRefresh() > 0 && this.druidLevel() >= 2 && this.accessToken()) {
        this.loadQuickState();
      }
    });
  }
  readonly wildShapeChanged = output<void>();
  private readonly http = inject(HttpClient);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly quickOpen = signal(false);
  protected readonly quickBusy = signal(false);
  protected readonly quickError = signal('');
  protected readonly quickBeasts = signal<any[]>([]);
  protected readonly quickState = signal<{usesRemaining: number; active: any} | null>(null);
  protected readonly quickSelected = signal(0);
  protected readonly quickMethod = signal<'average' | 'rolled'>('average');
  protected readonly quickRoll = signal('');
  protected readonly quickEquipment = signal('merge');
  ngOnInit(): void { if (this.druidLevel() >= 2 && this.accessToken()) this.loadQuickState(); }
  protected isWildResource(resource: CharacterResource): boolean { return this.druidLevel() >= 2 && /(?:utilisations? de )?forme sauvage|wild shape/i.test(resource.name); }
  private quickUrl(): string { return `/api/public/characters/${encodeURIComponent(this.accessToken())}/wild-shape`; }
  protected loadQuickState(): void { this.http.get<{wildShape: {usesRemaining: number; active: any}}>(this.quickUrl()).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({next: r => this.quickState.set(r.wildShape), error: () => this.quickError.set('État de Forme sauvage indisponible.')}); }
  protected openQuick(): void { this.quickOpen.set(true); this.quickError.set(''); this.loadQuickState(); this.http.get<{beasts: any[]}>(`/api/public/characters/${encodeURIComponent(this.accessToken())}/beasts`).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({next: r => { this.quickBeasts.set((r.beasts ?? []).filter(b => b.seen && this.quickEligible(b))); if (!this.quickBeasts().some(b => b.id === this.quickSelected())) this.quickSelected.set(this.quickBeasts()[0]?.id ?? 0); },error: () => this.quickError.set('Impossible de charger les formes disponibles.')}); }
  protected quickCr(beast: any): number { const v = String(beast.challengeRating ?? ''); if (v.includes('/')) { const [a,b] = v.split('/').map(Number); return b ? a/b : Infinity; } return v ? Number(v) : Infinity; }
  protected quickEligible(beast: any): boolean { if (!beast.statBlock) return false; const level = this.druidLevel(); const maxCr = this.moonCircle() ? (level < 6 ? 1 : Math.floor(level/3)) : (level < 4 ? .25 : level < 8 ? .5 : 1); return this.quickCr(beast) <= maxCr && (level >= 4 || !(beast.statBlock.speed?.swim > 0)) && (level >= 8 || !(beast.statBlock.speed?.fly > 0)); }
  protected quickBeast(): any | null { return this.quickBeasts().find(b => b.id === this.quickSelected()) ?? null; }
  protected quickBounds(): {min:number;max:number;modifier:number}|null { const match = String(this.quickBeast()?.statBlock?.hitDice ?? '').replace(/\s/g,'').match(/^(\d+)d(\d+)(?:([+-])(\d+))?$/); if (!match) return null; const n=Number(match[1]), faces=Number(match[2]), modifier=match[4] ? Number(match[4])*(match[3]==='-'?-1:1):0; return {min:n,max:n*faces,modifier}; }
  protected quickRollValid(): boolean { const b=this.quickBounds(), v=this.quickRoll(); return !!b && /^\d+$/.test(v) && Number(v)>=b.min && Number(v)<=b.max && Number(v)+b.modifier>0; }
  protected quickSubmit(): void { const active=this.quickState()?.active, beast=this.quickBeast(); if (this.quickBusy() || this.readonly() || (!beast || !this.quickState()?.usesRemaining || (this.quickMethod()==='rolled' && !this.quickRollValid()))) return; this.quickBusy.set(true); this.quickError.set(''); const action='transform'; const payload={beastId:beast.id,equipment:this.quickEquipment(),...(this.quickMethod()==='rolled'?{rolledHitPoints:Number(this.quickRoll())}:{})}; this.http.post<{wildShape:{usesRemaining:number;active:any}}>(`${this.quickUrl()}/${action}`,payload).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({next:r=>{this.quickState.set(r.wildShape);this.quickBusy.set(false);this.quickOpen.set(false);this.wildShapeChanged.emit();},error:e=>{this.quickBusy.set(false);this.quickError.set(e?.error?.message ?? 'Transformation impossible.');this.loadQuickState();}}); }
  protected quickRevert(): void {
    if (this.quickBusy() || this.readonly() || !this.quickState()?.active) return;
    this.quickBusy.set(true);
    this.quickError.set('');
    this.http.post<{wildShape:{usesRemaining:number;active:any}}>(`${this.quickUrl()}/revert`, {})
      .pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
        next: r => { this.quickState.set(r.wildShape); this.quickBusy.set(false); this.quickOpen.set(false); this.wildShapeChanged.emit(); },
        error: e => { this.quickBusy.set(false); this.quickError.set(e?.error?.message ?? 'Retour impossible.'); this.loadQuickState(); },
      });
  }
  readonly readonly = input(false);
  readonly tinkeringNotes = input<TemplateRef<unknown> | null>(null);
  readonly hasSupplement = input(false);
  private readonly characterStateService =
    inject(CharacterStateService);

  readonly resources =
    input.required<readonly CharacterResource[]>();

  readonly standardResources = computed(() =>
    this.resources().filter(resource => !resource.storedValuesConfig),
  );

  protected changeResource(
    resource: CharacterResource,
    change: number,
  ): void {
    if (this.readonly()) return;
    const canIncreaseManually =
      resource.resetPeriod === 'manual' ||
      resource.allowManualIncrease === true;

    if (change > 0 && !canIncreaseManually) {
      return;
    }

    this.characterStateService.adjustResource(
      resource.id,
      change,
    );
  }

  protected canIncreaseResource(
    resource: CharacterResource,
  ): boolean {
    return (
      resource.resetPeriod === 'manual' ||
      resource.allowManualIncrease === true
    );
  }

  protected updateResourceNotes(
    resourceId: string,
    notes: string,
  ): void {
    if (this.readonly()) return;
    this.characterStateService.updateResourceNotes(
      resourceId,
      notes,
    );
  }

  protected resetPeriodLabel(
    resetPeriod: CharacterResource['resetPeriod'],
  ): string {
    switch (resetPeriod) {
      case 'short-rest':
        return 'repos court';

      case 'long-rest':
        return 'repos long';

      case 'manual':
        return 'manuelle';

      case 'dawn':
        return 'à l’aube';

      case 'never':
        return 'aucune';
    }
  }
}

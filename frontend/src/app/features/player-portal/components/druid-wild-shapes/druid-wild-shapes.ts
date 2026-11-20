import { Component, DestroyRef, OnInit, computed, inject, input, output, signal } from '@angular/core';
import { KeyValuePipe } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

type Beast = {
  id: number; slug: string; name: string | null; challengeRating: string | null;
  seen: boolean; habitats: string[]; sourceUrl: string | null;
  statBlock: { armorClass?: number; hitPoints?: number; hitDice?: string; speed?: Record<string, number>; abilities?: Record<string, number>;
    actions?: Array<{ name: string; description?: string; attackBonus?: number }>;
    traits?: Array<{ name: string; desc?: string }> } | null;
};
const HABITATS = ['aquatique', 'arctique', 'collines', 'desert', 'foret', 'littoral', 'marais', 'montagne', 'plaine', 'outreterre'];
@Component({
  selector: 'app-druid-wild-shapes',
  standalone: true,
  imports: [KeyValuePipe],
  templateUrl: './druid-wild-shapes.html',
  styleUrl: './druid-wild-shapes.scss',
})
export class DruidWildShapes implements OnInit {
  private readonly http = inject(HttpClient);
  private readonly destroyRef = inject(DestroyRef);
  readonly token = input.required<string>();
  readonly druidLevel = input.required<number>();
  readonly moonCircle = input(false);
  readonly transformed = output<void>();
  readonly wildState = signal<{ usesRemaining: number; active: null | {
    beastId: number; beastName: string; hitPoints: { current: number; maximum: number };
    armorClass: number; durationMinutes: number; elapsedMinutes: number;
    equipment: string;
  } }>({ usesRemaining: 2, active: null });
  readonly mutating = signal(false);
  readonly equipmentChoice = signal<'merge' | 'drop' | 'wear'>('merge');
  readonly hitPointMethod = signal<'average' | 'rolled'>('average');
  readonly rolledHitPoints = signal('');
  hitDiceBounds(beast: Beast): { min: number; max: number; modifier: number } | null {
    const dice = beast.statBlock?.hitDice?.replace(/\s/g, '').match(/^(\d+)d(\d+)(?:([+-])(\d+))?$/);
    if (!dice) return null;
    const count = Number(dice[1]); const faces = Number(dice[2]);
    const modifier = dice[4] ? Number(dice[4]) * (dice[3] === '-' ? -1 : 1) : 0;
    if (count < 1 || count > 100 || faces < 2 || faces > 1000) return null;
    return { min: count, max: count * faces, modifier };
  }
  validRolledHitPoints(beast: Beast): boolean {
    const bounds = this.hitDiceBounds(beast);
    const value = this.rolledHitPoints().trim();
    return !!bounds && /^\d+$/.test(value) && Number(value) >= bounds.min && Number(value) <= bounds.max;
  }
  readonly beasts = signal<Beast[]>([]);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal('');
  readonly catalog = signal(false);
  readonly search = signal('');
  readonly habitat = signal('');
  readonly selected = signal<number | null>(null);
  readonly habitats = HABITATS;
  readonly filtered = computed(() => this.beasts().filter(beast =>
    (this.catalog() || beast.seen) &&
    (!this.habitat() || beast.habitats.includes(this.habitat())) &&
    (!this.search() || `${beast.name ?? ''} ${beast.slug}`.toLowerCase().includes(this.search().toLowerCase()))));
  readonly seenCount = computed(() => this.beasts().filter(beast => beast.seen).length);
  readonly selectedBeast = computed(() => this.beasts().find(beast => beast.id === this.selected()) ?? null);

  ngOnInit(): void { this.reload(); this.loadWildState(); }
  private wildUrl(): string { return `/api/public/characters/${encodeURIComponent(this.token())}/wild-shape`; }
  loadWildState(): void {
    this.http.get<{ wildShape: { usesRemaining: number; active: any } }>(this.wildUrl())
      .pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
        next: response => this.wildState.set(response.wildShape ?? { usesRemaining: 2, active: null }),
        error: () => this.error.set('Impossible de récupérer l’état de Forme sauvage.'),
      });
  }
  mutateWildShape(action: 'transform' | 'revert', beast?: Beast): void {
    if (action === 'transform' && beast && this.hitPointMethod() === 'rolled' && !this.validRolledHitPoints(beast)) { this.error.set('Somme des dés invalide (sans le modificateur de Constitution).'); return; }
    if (this.mutating() || (action === 'transform' && (!beast || !beast.seen || !this.eligible(beast) || this.wildState().active !== null || this.wildState().usesRemaining < 1))) return;
    this.mutating.set(true);
    this.error.set('');
    const payload = action === 'transform' ? { beastId: beast!.id, equipment: this.equipmentChoice(), ...(this.hitPointMethod() === 'rolled' ? { rolledHitPoints: Number(this.rolledHitPoints()) } : {}) } : {};
    this.http.post<{ wildShape: { usesRemaining: number; active: any } }>(`${this.wildUrl()}/${action}`, payload)
      .pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
        next: response => {
          this.wildState.set(response.wildShape);
          this.mutating.set(false);
          this.transformed.emit();
        },
        error: response => {
          this.error.set(response?.error?.message ?? 'Action de Forme sauvage impossible.');
          this.mutating.set(false);
          this.loadWildState();
        },
      });
  }
  private url(): string { return `/api/public/characters/${encodeURIComponent(this.token())}/beasts`; }
  reload(): void {
    this.loading.set(true); this.error.set('');
    this.http.get<{ beasts: Beast[] }>(this.url()).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: response => { this.beasts.set(response.beasts); this.loading.set(false); },
      error: () => { this.error.set('Impossible de charger le bestiaire.'); this.loading.set(false); },
    });
  }
  setSeen(beast: Beast, seen: boolean): void { this.update([beast.id], seen); }
  setHabitatSeen(seen: boolean): void {
    const ids = this.beasts().filter(beast => beast.habitats.includes(this.habitat()) && beast.seen !== seen).map(beast => beast.id);
    this.update(ids, seen);
  }
  private update(ids: number[], seen: boolean): void {
    if (!ids.length || this.saving()) return;
    this.saving.set(true); this.error.set('');
    this.http.put(`${this.url()}/seen/bulk`, { ids, seen }).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: () => { this.beasts.update(items => items.map(beast => ids.includes(beast.id) ? { ...beast, seen } : beast)); this.saving.set(false); },
      error: () => { this.error.set('Impossible de sauvegarder les observations.'); this.saving.set(false); },
    });
  }
  cr(beast: Beast): number { const value = beast.challengeRating ?? ''; if (value.includes('/')) { const [a,b] = value.split('/').map(Number); return b ? a/b : Infinity; } return value === '' ? Infinity : Number(value); }
  eligible(beast: Beast): boolean {
    if (!beast.statBlock || this.druidLevel() < 2) return false;
    const maxCr = this.moonCircle() ? (this.druidLevel() < 6 ? 1 : Math.floor(this.druidLevel() / 3)) : (this.druidLevel() < 4 ? .25 : this.druidLevel() < 8 ? .5 : 1);
    if (this.cr(beast) > maxCr) return false;
    if (this.druidLevel() < 4 && (beast.statBlock.speed?.['swim'] ?? 0) > 0) return false;
    if (this.druidLevel() < 8 && (beast.statBlock.speed?.['fly'] ?? 0) > 0) return false;
    return true;
  }
  reason(beast: Beast): string {
    if (!beast.statBlock) return 'Fiche de statistiques manquante';
    if (this.druidLevel() < 2) return 'Forme sauvage disponible à partir du niveau 2';
    if (this.druidLevel() < 4 && (beast.statBlock.speed?.['swim'] ?? 0) > 0) return 'Nage accessible au niveau 4';
    if (this.druidLevel() < 8 && (beast.statBlock.speed?.['fly'] ?? 0) > 0) return 'Vol accessible au niveau 8';
    return 'FP supérieur à la limite de forme sauvage';
  }
}

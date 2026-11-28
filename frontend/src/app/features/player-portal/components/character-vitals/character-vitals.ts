import {
  Component,
  inject,
  input,
  output,
  effect,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';

import { Character } from '@core/models/character.model';
import { CharacterStateService } from '@core/services/character-state.service';

@Component({
  selector: 'app-character-vitals',
  imports: [FormsModule],
  templateUrl: './character-vitals.html',
  styleUrl: './character-vitals.scss',
})
export class CharacterVitals {
  readonly accessToken = input('');
  readonly druidLevel = input(0);
  readonly wildShapeRefreshVersion = input(0);
  readonly stateChanged = output<void>();
  protected readonly wildShape = signal<{active: null | {beastName: string; armorClass: number; hitPoints: {current: number; maximum: number}}} | null>(null);
  protected readonly wildShapeBusy = signal(false);
  protected readonly wildShapeError = signal('');
  private readonly http = inject(HttpClient);
  constructor() {
    effect(() => {
      this.wildShapeRefreshVersion();
      this.accessToken();
      this.refreshWildShape();
    });
  }
  private wildUrl(): string { return `/api/public/characters/${encodeURIComponent(this.accessToken())}/wild-shape`; }
  private refreshWildShape(): void {
    if (this.druidLevel() < 2 || !this.accessToken()) {
        this.wildShape.set(null);
        this.wildShapeError.set('');
        return;
    }
    this.http.get<{wildShape: {active: any}}>(this.wildUrl()).subscribe({
      next: response => this.wildShape.set(response.wildShape),
      error: () => this.wildShapeError.set('État de la forme sauvage indisponible.'),
    });
  }
  private mutateWildShape(action: 'damage' | 'heal', amount: number): void {
    if (this.wildShapeBusy()) return;
    this.wildShapeBusy.set(true);
    this.wildShapeError.set('');
    this.http.post<{wildShape: {active: any}; hitPoints?: {current: number}}>(`${this.wildUrl()}/${action}`, {amount}).subscribe({
      next: response => {
        this.wildShape.set(response.wildShape);
        this.wildShapeBusy.set(false);
        this.hitPointAmount = null;
        this.stateChanged.emit();
      },
      error: response => { this.wildShapeBusy.set(false); this.wildShapeError.set(response?.error?.message ?? 'Action impossible.'); },
    });
  }
  readonly readonly = input(false);
  private readonly characterStateService =
    inject(CharacterStateService);

  readonly character = input.required<Character>();
  protected hitPointAmount: number | null = null;

  protected applyDamage(): void {
    if (this.readonly()) return;
    if (this.hitPointAmount === null || !Number.isFinite(this.hitPointAmount) || this.hitPointAmount <= 0) {
      return;
    }

    if (this.wildShape()?.active) { this.mutateWildShape('damage', Math.trunc(this.hitPointAmount)); return; }
    this.characterStateService.applyDamage(
      this.hitPointAmount,
    );

    this.hitPointAmount = null;
  }

  protected heal(): void {
    if (this.readonly()) return;
    if (this.hitPointAmount === null || !Number.isFinite(this.hitPointAmount) || this.hitPointAmount <= 0) {
      return;
    }

    if (this.wildShape()?.active) { this.mutateWildShape('heal', Math.trunc(this.hitPointAmount)); return; }
    this.characterStateService.heal(
      this.hitPointAmount,
    );

    this.hitPointAmount = null;
  }

  protected changeTemporaryHitPoints(change: number): void {
    if (this.readonly()) return;
    this.characterStateService.adjustTemporaryHitPoints(change);
  }

  protected spendHitDie(hitDicePoolId: string): void {
    if (this.readonly()) return;
    this.characterStateService.adjustHitDice(
      hitDicePoolId,
      -1,
    );
  }
}

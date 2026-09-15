import { Component, computed, input, output, signal } from '@angular/core';

export interface HeroesFeastTarget {
  id: number;
  name: string;
}

export interface HeroesFeastActionPayload {
  hitPointBonus: number;
  targetIds: number[];
}

@Component({
  selector: 'app-heroes-feast-action-modal',
  templateUrl: './heroes-feast-action-modal.html',
  styleUrl: './heroes-feast-action-modal.scss',
})
export class HeroesFeastActionModal {
  readonly targets = input.required<HeroesFeastTarget[]>();

  readonly closed = output<void>();
  readonly confirmed = output<HeroesFeastActionPayload>();

  protected readonly hitPointBonus = signal<number | null>(null);
  protected readonly selectedTargets = signal<number[]>([]);

  protected readonly canConfirm = computed(() => {
    const bonus = this.hitPointBonus();

    return bonus !== null && bonus >= 2 && bonus <= 20 && this.selectedTargets().length >= 1 && this.selectedTargets().length <= 12;
  });

  protected updateHitPointBonus(event: Event): void {
    const value = Number((event.target as HTMLInputElement).value);
    this.hitPointBonus.set(Number.isInteger(value) ? value : null);
  }

  protected toggleTarget(characterId: number): void {
    const selected = this.selectedTargets();

    if (selected.includes(characterId)) {
      this.selectedTargets.set(selected.filter(id => id !== characterId));
      return;
    }

    if (selected.length >= 12) {
      return;
    }

    this.selectedTargets.set([...selected, characterId]);
  }

  protected isTargetSelected(characterId: number): boolean {
    return this.selectedTargets().includes(characterId);
  }

  protected confirm(): void {
    const bonus = this.hitPointBonus();

    if (bonus === null || !this.canConfirm()) {
      return;
    }

    this.confirmed.emit({ hitPointBonus: bonus, targetIds: this.selectedTargets() });
  }
}

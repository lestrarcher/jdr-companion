import { Component, computed, input, output, signal } from '@angular/core';
import { CharacterResource } from '@core/models/character.model';

export interface FlexibleCastingPayload { mode: 'create' | 'convert'; level: number; }

@Component({
  selector: 'app-flexible-casting-action-modal',
  templateUrl: './flexible-casting-action-modal.html',
  styleUrls: ['../aid-action-modal/aid-action-modal.scss', './flexible-casting-action-modal.scss'],
})
export class FlexibleCastingActionModal {
  readonly resources = input.required<readonly CharacterResource[]>();
  readonly saving = input(false);
  readonly error = input<string | null>(null);
  readonly closed = output<void>();
  readonly confirmed = output<FlexibleCastingPayload>();
  protected readonly mode = signal<'create' | 'convert'>('create');
  protected readonly selectedLevel = signal<number | null>(null);
  protected readonly costs = [{ level: 1, cost: 2 }, { level: 2, cost: 3 }, { level: 3, cost: 5 }, { level: 4, cost: 6 }, { level: 5, cost: 7 }];
  protected readonly points = computed(() => this.resources().find(resource => resource.id === 'sorcery-points'));
  protected readonly slots = computed(() => this.resources()
    .filter(resource => /^spell-slot-[1-9]$/.test(resource.id) && resource.currentValue > 0)
    .map(resource => ({ level: Number(resource.id.slice('spell-slot-'.length)), current: resource.currentValue, maximum: resource.maximumValue }))
    .sort((a, b) => a.level - b.level));
  protected readonly canConfirm = computed(() => {
    const level = this.selectedLevel();
    return level !== null && !this.saving() && this.canSelect(level);
  });

  protected canSelect(level: number): boolean {
    const points = this.points();
    if (!points) return false;
    if (this.mode() === 'create') return this.costs.some(option => option.level === level && option.cost <= points.currentValue);
    return this.slots().some(slot => slot.level === level) && points.currentValue + level <= points.maximumValue;
  }

  protected changeMode(mode: 'create' | 'convert'): void {
    this.mode.set(mode);
    this.selectedLevel.set(null);
  }

  protected confirm(): void {
    if (this.canConfirm()) this.confirmed.emit({ mode: this.mode(), level: this.selectedLevel()! });
  }
}

import { Component, computed, input, output, signal } from '@angular/core';
import { CharacterResource } from '@core/models/character.model';

export interface ArcaneRecoveryPayload { slots: Record<number, number>; }

@Component({
  selector: 'app-arcane-recovery-action-modal',
  templateUrl: './arcane-recovery-action-modal.html',
  styleUrls: ['../aid-action-modal/aid-action-modal.scss', './arcane-recovery-action-modal.scss'],
})
export class ArcaneRecoveryActionModal {
  readonly resources = input.required<readonly CharacterResource[]>();
  readonly budget = input.required<number>();
  readonly saving = input(false);
  readonly error = input<string | null>(null);
  readonly closed = output<void>();
  readonly confirmed = output<ArcaneRecoveryPayload>();

  protected readonly selections = signal<Record<number, number>>({});
  protected readonly remainingBudget = computed(() => this.budget() - this.usedBudget());
  protected readonly canConfirm = computed(() => this.usedBudget() > 0 && this.usedBudget() <= this.budget() && !this.saving());
  protected readonly usedBudget = computed(() => Object.entries(this.selections()).reduce((total, [level, quantity]) => total + Number(level) * quantity, 0));
  protected readonly slots = computed(() => this.resources()
    .filter(resource => /^spell-slot-[1-5]$/.test(resource.id) && resource.currentValue < resource.maximumValue)
    .map(resource => ({ level: Number(resource.id.slice('spell-slot-'.length)), current: resource.currentValue, maximum: resource.maximumValue, missing: resource.maximumValue - resource.currentValue }))
    .sort((a, b) => a.level - b.level));

  protected selected(level: number): number {
    return this.selections()[level] ?? 0;
  }

  protected canAdd(level: number, missing: number): boolean {
    return !this.saving() && this.selected(level) < missing && this.remainingBudget() >= level;
  }

  protected add(level: number, missing: number): void {
    if (!this.canAdd(level, missing)) return;
    this.selections.update(selections => ({ ...selections, [level]: this.selected(level) + 1 }));
  }

  protected remove(level: number): void {
    if (this.saving() || this.selected(level) < 1) return;
    this.selections.update(selections => ({ ...selections, [level]: this.selected(level) - 1 }));
  }

  protected confirm(): void {
    if (!this.canConfirm()) return;
    const slots = Object.fromEntries(Object.entries(this.selections()).filter(([, quantity]) => quantity > 0).map(([level, quantity]) => [Number(level), quantity]));
    this.confirmed.emit({ slots });
  }
}

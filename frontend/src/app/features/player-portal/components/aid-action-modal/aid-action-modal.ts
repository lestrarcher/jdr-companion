import {
  Component,
  computed,
  input,
  output,
  signal,
} from '@angular/core';

export interface AidSpellSlot {
  level: number;
  current: number;
}

export interface AidTarget {
  id: number;
  name: string;
}

export interface AidActionPayload {
  spellSlotLevel: number;
  targetIds: number[];
}

@Component({
  selector: 'app-aid-action-modal',
  templateUrl: './aid-action-modal.html',
  styleUrl: './aid-action-modal.scss',
})
export class AidActionModal {
  readonly spellSlots = input.required<AidSpellSlot[]>();
  readonly targets = input.required<AidTarget[]>();

  readonly closed = output<void>();
  readonly confirmed = output<AidActionPayload>();

  protected readonly selectedLevel = signal<number | null>(null);
  protected readonly selectedTargets = signal<number[]>([]);

  protected readonly bonus = computed(() => {
    const level = this.selectedLevel();

    return level === null
      ? 0
      : 5 * (level - 1);
  });

  protected readonly canConfirm = computed(() =>
    this.selectedLevel() !== null
    && this.selectedTargets().length >= 1
    && this.selectedTargets().length <= 3,
  );

  protected selectLevel(level: number): void {
    this.selectedLevel.set(level);
  }

  protected toggleTarget(characterId: number): void {
    const selected = this.selectedTargets();

    if (selected.includes(characterId)) {
      this.selectedTargets.set(
        selected.filter(id => id !== characterId),
      );

      return;
    }

    if (selected.length >= 3) {
      return;
    }

    this.selectedTargets.set([
      ...selected,
      characterId,
    ]);
  }

  protected isTargetSelected(characterId: number): boolean {
    return this.selectedTargets().includes(characterId);
  }

  protected confirm(): void {
    const level = this.selectedLevel();

    if (level === null || !this.canConfirm()) {
      return;
    }

    this.confirmed.emit({
      spellSlotLevel: level,
      targetIds: this.selectedTargets(),
    });
  }
}

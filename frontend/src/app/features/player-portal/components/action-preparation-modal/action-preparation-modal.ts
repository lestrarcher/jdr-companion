import {
  Component,
  effect,
  input,
  output,
  signal,
} from '@angular/core';

import {
  CharacterActionSummary,
} from '@core/services/character-api.service';

@Component({
  selector: 'app-action-preparation-modal',
  templateUrl: './action-preparation-modal.html',
  styleUrl: './action-preparation-modal.scss',
})
export class ActionPreparationModal {
  readonly actions = input.required<CharacterActionSummary[]>();
  readonly prepared = input<string[]>([]);
  readonly saving = input(false);
  readonly error = input<string | null>(null);

  readonly confirmed = output<string[]>();

  protected readonly selected = signal<string[]>([]);

  constructor() {
    effect(() => {
      this.selected.set([...this.prepared()]);
    });
  }

  protected isSelected(slug: string): boolean {
    return this.selected().includes(slug);
  }

  protected toggle(slug: string): void {
    if (this.saving()) {
      return;
    }

    const selected = this.selected();

    this.selected.set(
      selected.includes(slug)
        ? selected.filter((candidate) => candidate !== slug)
        : [...selected, slug],
    );
  }

  protected confirm(): void {
    if (this.saving()) {
      return;
    }

    this.confirmed.emit(this.selected());
  }
}

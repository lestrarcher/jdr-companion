import { Component, effect, inject, input, output, signal, untracked } from '@angular/core';
import { CharacterActionTarget, CharacterSessionStateApiService } from '@core/services/character-session-state-api.service';

@Component({
  selector: 'app-spell-target-picker',
  templateUrl: './spell-target-picker.html',
  styleUrl: './spell-target-picker.scss',
})
export class SpellTargetPicker {
  readonly accessToken = input.required<string>();
  readonly maximum = input.required<number>();
  readonly locked = input(false);
  readonly changed = output<number[]>();
  readonly targets = signal<CharacterActionTarget[]>([]);
  readonly selected = signal<number[]>([]);
  readonly loading = signal(false);
  readonly failed = signal(false);
  private readonly attempt = signal(0);
  private readonly api = inject(CharacterSessionStateApiService);

  constructor() {
    effect(onCleanup => {
      const token = this.accessToken(); this.attempt();
      // Output listeners must not become dependencies that reload the participant list.
      untracked(() => {
        this.selected.set([]); this.changed.emit([]); this.targets.set([]); this.failed.set(false);
        if (!token) { this.loading.set(false); this.failed.set(true); return; }
        this.loading.set(true);
        // Reuse the same session-scoped participant endpoint as the historical actions.
        const subscription = this.api.getActionTargets(token).subscribe({
          next: targets => {
            this.targets.set([...new Map(targets.filter(t => Number.isInteger(t.id) && t.id > 0).map(t => [t.id, t])).values()]);
            this.loading.set(false);
          },
          error: () => { this.loading.set(false); this.failed.set(true); },
        });
        onCleanup(() => subscription.unsubscribe());
      });
    });
  }

  retry(): void { if (!this.locked() && !this.loading()) this.attempt.update(value => value + 1); }
  toggle(id: number): void {
    if (this.locked() || this.loading() || !this.targets().some(target => target.id === id)) return;
    const selected = this.selected();
    if (!selected.includes(id) && selected.length >= this.maximum()) return;
    const next = selected.includes(id) ? selected.filter(value => value !== id) : [...selected, id];
    this.selected.set(next); this.changed.emit([...next]);
  }
}

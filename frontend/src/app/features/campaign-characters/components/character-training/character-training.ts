import { Component, computed, effect, inject, input, output, signal } from '@angular/core';
import { CharacterApiService, TrainingOptions } from '@core/services/character-api.service';

@Component({
  selector: 'app-character-training',
  template: `
    <section class="level-up-panel">
      <h3>Compléter les maîtrises</h3>
      <p>Choix de compétences, armes et outils encore incomplets au niveau actuel.</p>
      @if (error()) { <p role="alert" class="message message--error">{{ error() }}</p> }
      @if (!options() && !error()) { <p>Chargement…</p> }
      @for (rule of rules(); track rule.ruleId) {
        <fieldset class="training-choice" [disabled]="saving()">
          <legend>{{ rule.label }} — {{ (selection()[rule.ruleId] ?? []).length }}/{{ rule.choiceCount }}</legend>
          @for (option of definitions(rule.optionIds); track option.id) {
            <label class="training-option">
              <input type="checkbox" [checked]="(selection()[rule.ruleId] ?? []).includes(option.id)"
                (change)="toggle(rule.ruleId, option.id, rule.choiceCount)" />
              <span>{{ option.name }}</span>
            </label>
          }
        </fieldset>
      } @empty {
        @if (options()) { <p>Aucun choix de maîtrise à compléter.</p> }
      }
      <footer class="form-actions">
        <button type="button" class="secondary-button" [disabled]="saving()" (click)="cancelled.emit()">Fermer</button>
        <button type="button" class="primary-button" [disabled]="!complete() || saving()" (click)="save()">Enregistrer les choix</button>
      </footer>
    </section>
  `,
  styleUrl: '../character-level-up/character-level-up.scss',
})
export class CharacterTraining {
  readonly characterId = input.required<number>();
  readonly completed = output<void>();
  readonly cancelled = output<void>();
  readonly options = signal<TrainingOptions | null>(null);
  readonly selection = signal<Record<number, number[]>>({});
  readonly saving = signal(false);
  readonly error = signal('');
  private readonly api = inject(CharacterApiService);
  readonly rules = computed(() => this.options()?.proficiencyRules.filter(rule =>
    this.options()?.choices.some(choice => choice.kind === 'proficiency' && choice.ruleId === rule.ruleId && choice.status !== 'complete')) ?? []);
  readonly complete = computed(() => this.rules().length > 0 && this.rules().every(rule => {
    const values = this.selection()[rule.ruleId] ?? [];
    return values.length === rule.choiceCount && values.every(id => rule.optionIds.includes(id));
  }));
  constructor() {
    effect(onCleanup => {
      this.options.set(null); this.error.set(''); this.selection.set({});
      const subscription = this.api.getTrainingOptions(this.characterId()).subscribe({
        next: options => {
          this.options.set(options);
          this.selection.set(Object.fromEntries(options.choices.filter(c => c.kind === 'proficiency').map(c => [c.ruleId!, c.selected.map(Number)])));
        },
        error: error => this.error.set(error?.error?.message ?? 'Chargement impossible.'),
      });
      onCleanup(() => subscription.unsubscribe());
    });
  }
  definitions(ids: number[]) { return this.options()?.definitions.proficiencies.filter(d => ids.includes(d.id)) ?? []; }
  toggle(rule: number, id: number, limit: number): void {
    if (this.saving()) return;
    const values = this.selection()[rule] ?? [];
    this.selection.update(all => ({ ...all, [rule]: values.includes(id) ? values.filter(v => v !== id) : values.length < limit ? [...values, id] : values }));
  }
  save(): void {
    if (!this.complete() || this.saving()) return;
    this.saving.set(true); this.error.set('');
    this.api.updateTrainingChoices(this.characterId(), { proficiencyChoices: this.rules().map(rule => ({ ruleId: rule.ruleId, proficiencyIds: this.selection()[rule.ruleId] })) }).subscribe({
      next: () => { this.saving.set(false); this.completed.emit(); },
      error: error => { this.saving.set(false); this.error.set(error?.error?.message ?? 'Enregistrement impossible.'); },
    });
  }
}

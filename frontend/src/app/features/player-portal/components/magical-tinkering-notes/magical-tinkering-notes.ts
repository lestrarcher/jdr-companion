import { Component, input, output, signal, effect, inject, DestroyRef } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize } from 'rxjs';

@Component({
  selector: 'app-magical-tinkering-notes',
  styleUrl: './magical-tinkering-notes.scss',
  template: `
    <section [class.resource-card]="!embedded()">
      @if (!embedded()) { <h3>Bricolages magiques</h3> }
      @if (editable()) {
        <label>Objets et effets décrits
          <textarea rows="2" maxlength="10000" [value]="draft()" [disabled]="saving()"
            (input)="draft.set($any($event.target).value)"></textarea>
        </label>
        <button type="button" [disabled]="saving() || busy() || draft() === notes()" (click)="save()">Enregistrer les bricolages</button>
      } @else {
        <p style="white-space: pre-wrap">{{ notes() || 'Aucun bricolage renseigné.' }}</p>
      }
      @if (error()) { <p role="alert">{{ error() }}</p> }
    </section>
  `,
})
export class MagicalTinkeringNotes {
  readonly embedded = input(false);
  readonly notes = input('');
  readonly editable = input(false);
  readonly busy = input(false);
  readonly token = input.required<string>();
  readonly revision = input.required<number>();
  readonly refreshed = output<void>();
  readonly draft = signal('');
  readonly saving = signal(false);
  readonly error = signal('');
  private readonly http = inject(HttpClient);
  private readonly destroyRef = inject(DestroyRef);
  constructor() { effect(() => this.draft.set(this.notes())); }
  save(): void {
    if (!this.editable() || this.busy() || this.saving()) return;
    this.saving.set(true); this.error.set('');
    this.http.patch(`/api/public/characters/${encodeURIComponent(this.token())}`, {
      revision: this.revision(), state: { magicalTinkeringNotes: this.draft() },
    }).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.saving.set(false))).subscribe({
      next: () => this.refreshed.emit(),
      error: error => {
        this.error.set(error?.status === 409 ? 'Conflit : données rechargées. Vérifiez avant de réessayer.' : (error?.error?.message ?? 'Enregistrement impossible.'));
        if (error?.status === 409) this.refreshed.emit();
      },
    });
  }
}

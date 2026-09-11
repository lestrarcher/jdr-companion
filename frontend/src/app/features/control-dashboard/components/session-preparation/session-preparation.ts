import { Component, DestroyRef, HostListener, Input, OnChanges, ViewEncapsulation, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Subscription, finalize } from 'rxjs';
import { GameSessionApiService } from '@core/services/game-session-api.service';
import { renderPreparationMarkdown } from './preparation-markdown';

@Component({
  selector: 'app-session-preparation',
  templateUrl: './session-preparation.html',
  styleUrl: './session-preparation.scss',
  // Generated Markdown has no Angular scoping attributes. Every style is
  // explicitly scoped beneath app-session-preparation instead.
  encapsulation: ViewEncapsulation.None,
})
export class SessionPreparation implements OnChanges {
  @Input({ required: true }) sessionId!: number;
  private readonly api = inject(GameSessionApiService);
  private readonly destroyRef = inject(DestroyRef);
  private loadRequest?: Subscription;
  readonly draft = signal('');
  private readonly saved = signal('');
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly loaded = signal(false);
  readonly error = signal<string | null>(null);
  readonly mode = signal<'edit' | 'preview'>('edit');
  readonly dirty = computed(() => this.draft() !== this.saved());
  readonly byteCount = computed(() => new TextEncoder().encode(this.draft()).length);
  readonly tooLarge = computed(() => this.byteCount() > 100_000);
  readonly preview = computed(() => renderPreparationMarkdown(this.draft()));

  ngOnChanges(): void {
    this.load();
  }

  load(): void {
    this.loadRequest?.unsubscribe();
    this.loading.set(true);
    this.loaded.set(false);
    this.error.set(null);
    this.draft.set('');
    this.saved.set('');
    this.mode.set('edit');
    const sessionId = this.sessionId;
    this.loadRequest = this.api.get(sessionId).pipe(
      takeUntilDestroyed(this.destroyRef),
      finalize(() => this.loading.set(false)),
    ).subscribe({
      next: session => {
        if (sessionId !== this.sessionId) return;
        this.draft.set(session.preparationNotes ?? '');
        this.saved.set(this.draft());
        this.loaded.set(true);
      },
      error: () => this.error.set('Impossible de charger les notes de cette session.'),
    });
  }

  edit(value: string): void {
    this.draft.set(value);
    this.error.set(null);
  }

  save(): void {
    if (!this.loaded() || !this.dirty() || this.saving() || this.tooLarge()) return;
    const sessionId = this.sessionId;
    const submitted = this.draft();
    this.saving.set(true);
    this.error.set(null);
    this.api.updatePreparation(sessionId, submitted).pipe(
      takeUntilDestroyed(this.destroyRef),
      finalize(() => this.saving.set(false)),
    ).subscribe({
      next: session => {
        if (sessionId !== this.sessionId) return;
        const persisted = session.preparationNotes ?? '';
        this.saved.set(persisted);
        // Do not erase an edit made while this request was in flight.
        if (this.draft() === submitted) this.draft.set(persisted);
      },
      error: () => this.error.set('Enregistrement impossible. Vos modifications sont conservées ; réessayez.'),
    });
  }

  canLeave(): boolean {
    if (this.saving()) {
      this.error.set('Veuillez attendre la fin de l’enregistrement avant de quitter la session.');
      return false;
    }
    return !this.dirty() || window.confirm('Des modifications de préparation ne sont pas enregistrées. Quitter cette session et les abandonner ?');
  }

  @HostListener('window:beforeunload', ['$event'])
  beforeUnload(event: BeforeUnloadEvent): void {
    if (this.dirty() || this.saving()) {
      event.preventDefault();
      event.returnValue = '';
    }
  }
}

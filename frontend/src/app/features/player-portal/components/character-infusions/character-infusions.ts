import { Component, DestroyRef, ElementRef, OnChanges, computed, inject, input, output, signal, viewChild } from '@angular/core';
import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Subscription, forkJoin } from 'rxjs';

export interface KnownInfusion { choiceKey: string; featureKey: string; name: string; mode: 'existing-item' | 'create-object' }
export interface ActiveInfusion { id: number; creatorId: number; itemId: number; itemName: string; recipientId: number | null; choiceKey: string; createdAt: string }
export interface InfusionView { known: KnownInfusion[]; quota: number; active: ActiveInfusion[]; preparation?: { id: string | null; open: boolean } }
interface Recipient { id: number; name: string }
interface Support { id: number; name: string; eligible: boolean; reason: string | null }
interface Selection { choiceKey: string; recipientId: number | null; itemId: number; itemName: string }

@Component({ selector: 'app-character-infusions', templateUrl: './character-infusions.html', styleUrl: './character-infusions.scss' })
export class CharacterInfusions implements OnChanges {
  private readonly http = inject(HttpClient);
  private readonly destroyRef = inject(DestroyRef);
  private readonly dialog = viewChild<ElementRef<HTMLDialogElement>>('dialog');
  private itemRequest?: Subscription;
  private loadRequest?: Subscription;
  readonly accessToken = input.required<string>();
  readonly refresh = input(0);
  readonly readonly = input(false);
  readonly changed = output<void>();
  readonly view = signal<InfusionView | null>(null);
  readonly recipients = signal<Recipient[]>([]);
  readonly items = signal<Support[]>([]);
  readonly draft = signal<Selection[]>([]);
  readonly loading = signal(false);
  readonly itemsLoading = signal(false);
  readonly busy = signal(false);
  readonly error = signal('');
  readonly itemError = signal('');
  readonly success = signal('');
  readonly opened = signal(false);
  readonly choiceKey = signal('');
  readonly recipientId = signal(0);
  readonly itemId = signal(0);
  readonly selectedChoice = computed(() => this.view()?.known.find(c => c.choiceKey === this.choiceKey()));
  readonly phaseOpen = computed(() => !!this.view()?.preparation?.open && !!this.view()?.preparation?.id);
  readonly preparing = computed(() => this.phaseOpen() && !this.readonly());
  readonly editable = computed(() => this.preparing() && !this.busy() && !this.loading());
  readonly eligibleItems = computed(() => this.items().filter(item => item.eligible));
  readonly ordered = computed(() => {
    const retained = [...(this.view()?.active ?? [])].sort((a,b) => a.createdAt.localeCompare(b.createdAt) || a.id - b.id)
      .flatMap(a => this.draft().filter(d => d.choiceKey === a.choiceKey && d.itemId === a.itemId));
    return [...retained, ...this.draft().filter(d => !retained.includes(d))];
  });
  readonly evicted = computed(() => this.ordered().slice(0, Math.max(0, this.ordered().length - (this.view()?.quota ?? 0))));
  readonly planned = computed(() => this.ordered().slice(this.evicted().length));
  readonly ending = computed(() => (this.view()?.active ?? []).filter(a => !this.planned().some(d => d.choiceKey === a.choiceKey && d.itemId === a.itemId)));
  readonly canApply = computed(() => this.editable() && !this.itemsLoading() && !this.itemError()
    && this.selectedChoice()?.mode === 'existing-item' && this.eligibleItems().some(i => i.id === this.itemId()));
  private get url(): string { return `/api/public/characters/${this.accessToken()}/infusions`; }

  ngOnChanges(changes: Record<string, unknown>): void {
    if (changes['readonly'] && this.readonly()) this.cancel();
    if (changes['accessToken']) { this.view.set(null); this.draft.set([]); this.cancel(); }
    if (changes['accessToken'] || changes['refresh']) this.load();
  }
  load(): void {
    if (this.busy() || !this.accessToken()) return;
    this.loadRequest?.unsubscribe(); this.loading.set(true); this.error.set('');
    this.loadRequest = forkJoin({ view: this.http.get<InfusionView>(this.url), recipients: this.http.get<{recipients: Recipient[]}>(`${this.url}/recipients`) })
      .pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
        next: result => {
          const previous = this.view();
          if (!previous || previous.preparation?.id !== result.view.preparation?.id || !result.view.preparation?.open) {
            this.draft.set(result.view.active.map(a => ({choiceKey:a.choiceKey, recipientId:a.recipientId, itemId:a.itemId, itemName:a.itemName})));
            this.cancel();
          }
          this.view.set(result.view); this.recipients.set(result.recipients.recipients); this.loading.set(false);
        },
        error: error => { this.loading.set(false); this.error.set(this.message(error)); },
      });
  }
  name(key: string): string { return this.view()?.known.find(c => c.choiceKey === key)?.name ?? 'Imprégnation'; }
  owner(id: number | null): string { return this.recipients().find(r => r.id === id)?.name ?? 'Sans propriétaire'; }
  applied(key: string): boolean { return !!this.view()?.active.some(a => a.choiceKey === key); }
  retained(entry: Selection): boolean { return !!this.view()?.active.some(a => a.choiceKey === entry.choiceKey && a.itemId === entry.itemId); }
  glyph(choice: KnownInfusion): string {
    if (choice.mode === 'create-object') return '◇';
    return /defense|armor|shield/.test(choice.featureKey) ? '⬡' : /weapon|shot/.test(choice.featureKey) ? 'ϟ' : '✧';
  }
  selectChoice(key: string): void {
    if (!this.editable() || !this.view()?.known.some(choice => choice.choiceKey === key)) return;
    this.resetItems(); this.choiceKey.set(key); this.recipientId.set(0); this.opened.set(true);
    this.dialog()?.nativeElement.showModal();
    const current = this.draft().find(d => d.choiceKey === key);
    if (this.editable() && this.selectedChoice()?.mode === 'existing-item' && current?.recipientId) this.selectRecipient(current.recipientId, current.itemId);
  }
  cancel(): void {
    if (this.busy()) return;
    const dialog = this.dialog()?.nativeElement;
    if (dialog?.open) dialog.close();
    this.resetItems(); this.opened.set(false); this.choiceKey.set(''); this.recipientId.set(0);
  }
  selectRecipient(id: number, selectedItem = 0): void {
    if (!this.editable()) return;
    this.resetItems(); this.recipientId.set(id);
    if (!id || !this.choiceKey()) return;
    this.itemsLoading.set(true);
    this.itemRequest = this.http.get<{items: Support[]}>(`${this.url}/recipients/${id}/items`, {params:new HttpParams().set('choiceKey', this.choiceKey())})
      .pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
        next: result => { this.items.set(result.items); this.itemsLoading.set(false); this.itemId.set(selectedItem); },
        error: error => { this.itemsLoading.set(false); this.itemError.set(this.message(error)); },
      });
  }
  private resetItems(): void { this.itemRequest?.unsubscribe(); this.items.set([]); this.itemId.set(0); this.itemsLoading.set(false); this.itemError.set(''); }
  apply(): void {
    if (!this.canApply()) return;
    const entry = {choiceKey:this.choiceKey(), recipientId:this.recipientId(), itemId:this.itemId(), itemName:this.eligibleItems().find(i => i.id === this.itemId())!.name};
    // One formula and one support per draft; moving either replaces its previous selection.
    this.draft.update(rows => [...rows.filter(d => d.choiceKey !== entry.choiceKey && d.itemId !== entry.itemId), entry]);
    this.cancel();
  }
  remove(key: string): void { if (this.editable()) { this.draft.update(rows => rows.filter(d => d.choiceKey !== key)); this.cancel(); } }
  confirm(): void {
    const phaseId = this.view()?.preparation?.id;
    if (!this.editable() || !phaseId || this.opened()) return;
    this.loadRequest?.unsubscribe(); this.busy.set(true); this.error.set(''); this.success.set('');
    const selections = this.ordered().map(({choiceKey, recipientId, itemId}) => ({choiceKey, recipientId, itemId}));
    this.http.post<InfusionView>(`${this.url}/preparation/confirm`, {phaseId, selections})
      .pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
        next: view => { this.view.set(view); this.draft.set(view.active.map(a => ({choiceKey:a.choiceKey, recipientId:a.recipientId, itemId:a.itemId, itemName:a.itemName}))); this.busy.set(false); this.success.set('Préparation des imprégnations terminée.'); this.changed.emit(); },
        error: error => { this.busy.set(false); this.error.set(this.message(error)); },
      });
  }
  private message(error: HttpErrorResponse): string {
    const message = error.error?.message ?? error.error?.detail;
    if ([400,403,404,409,422].includes(error.status) && typeof message === 'string') return message;
    return 'Impossible de confirmer ou charger les imprégnations. Votre brouillon est conservé ; vérifiez la connexion puis réessayez.';
  }
}

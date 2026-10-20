import { Component, computed, DestroyRef, inject, input, output, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { finalize, forkJoin, Observable } from 'rxjs';
import { CustomContentApiService } from '@core/services/custom-content-api.service';
import { CustomFeature, CustomFeatureRule, CustomResource, CustomResourceRule } from '@core/models/custom-content.model';

type Kind = 'features' | 'feature-rules' | 'resource-rules';
type Entry = CustomFeature | CustomFeatureRule | CustomResourceRule;
@Component({ selector: 'app-definition-manager', imports: [FormsModule], templateUrl: './definition-manager.html', styleUrl: './definition-manager.scss' })
export class DefinitionManager {
  readonly kind = input.required<Kind>();
  readonly busyChange = output<boolean>();
  private readonly api = inject(CustomContentApiService);
  private readonly destroyRef = inject(DestroyRef);
  readonly entries = signal<Entry[]>([]);
  readonly features = signal<CustomFeature[]>([]);
  readonly resources = signal<CustomResource[]>([]);
  readonly busy = signal(false);
  readonly error = signal('');
  readonly feedback = signal('');
  readonly search = signal('');
  readonly sourceFilter = signal('all');
  readonly levelFilter = signal('all');
  readonly selectedId = signal<number | null>(null);
  readonly editing = signal(false);
  readonly name = signal(''); readonly description = signal(''); readonly resourceId = signal<number | null>(null);
  readonly definitionId = signal<number | null>(null); readonly sourceType = signal('class'); readonly sourceId = signal<number | null>(null);
  readonly unlockLevel = signal<number | null>(1); readonly threshold = signal<number | null>(0); readonly bonus = signal<number | null>(0);
  readonly filtered = computed(() => this.entries().filter(e => {
    if (!this.label(e).toLocaleLowerCase('fr').includes(this.search().toLocaleLowerCase('fr'))) return false;
    if (this.kind() !== 'feature-rules' || !('source' in e)) return true;
    if (this.sourceFilter() !== 'all' && e.source.type !== this.sourceFilter()) return false;
    if (this.levelFilter() !== 'all') {
      const value =
  e.source.type === 'progression' && 'progressionThreshold' in e
    ? e.progressionThreshold
    : e.unlockLevel;
      if (String(value ?? '') !== this.levelFilter()) return false;
    }
    return true;
  }));
  readonly selected = computed(() => this.entries().find(e => e.id === this.selectedId()) ?? null);
  ngOnChanges() {
    this.editing.set(false); this.selectedId.set(null); this.entries.set([]);
    this.search.set(''); this.sourceFilter.set('all'); this.levelFilter.set('all');
    this.load();
  }
  label(e: Entry): string {
    if ('name' in e) return e.name;
    return 'featureDefinition' in e ? `${e.featureDefinition.name} → ${e.source.name}` : `${e.resourceDefinition.name} → ${e.source.name}`;
  }
  private setBusy(value: boolean) {
    if (this.destroyRef.destroyed) return;
    this.busy.set(value); this.busyChange.emit(value);
  }
  load() {
    this.setBusy(true); this.error.set('');
    forkJoin({
      entries: this.kind() === 'features' ? this.api.features() : this.kind() === 'feature-rules' ? this.api.featureRules() : this.api.resourceRules(),
      features: this.api.features(), resources: this.api.resources(),
    }).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.setBusy(false))).subscribe({
      next: r => { this.entries.set(r.entries); this.features.set(r.features); this.resources.set(r.resources); },
      error: () => this.error.set('Impossible de charger le catalogue.'),
    });
  }
  canDiscard(): boolean { return !this.editing() || window.confirm('Abandonner les modifications non enregistrées ?'); }
  open(e: Entry | null) {
    if (this.busy() || !this.canDiscard()) return;
    this.error.set(''); this.feedback.set(''); this.selectedId.set(e?.id ?? null);
    this.name.set(e && 'name' in e ? e.name : '');
    this.description.set(e && 'description' in e ? e.description ?? '' : '');
    this.resourceId.set(e && 'resourceDefinition' in e && 'name' in e ? e.resourceDefinition?.id ?? null : null);
    this.definitionId.set(e && 'featureDefinition' in e ? e.featureDefinition.id : e && 'resourceDefinition' in e ? e.resourceDefinition?.id ?? null : null);
    this.sourceType.set(e && 'source' in e ? e.source.type : 'class');
    this.sourceId.set(e && 'source' in e ? e.source.id : null);
    this.unlockLevel.set(e && 'unlockLevel' in e ? e.unlockLevel : 1);
    this.threshold.set(e && 'progressionThreshold' in e ? e.progressionThreshold : 0);
    this.bonus.set(e && 'maximumBonus' in e ? e.maximumBonus : 0);
    this.editing.set(true);
  }
  close() { if (this.busy() || !this.canDiscard()) return; this.editing.set(false); }
  valid(): boolean {
    if (this.kind() === 'features') return this.name().trim().length > 0 && this.name().length <= 150;
    if (!this.definitionId() || !this.sourceId() || !Number.isInteger(this.sourceId())) return false;
    if (this.kind() === 'feature-rules' && this.sourceType() === 'progression') return Number.isInteger(this.threshold()) && this.threshold()! >= 0;
    return Number.isInteger(this.unlockLevel()) && this.unlockLevel()! >= 1 && this.unlockLevel()! <= 20 && (this.kind() !== 'resource-rules' || Number.isInteger(this.bonus()) && this.bonus()! >= 0);
  }
  save() {
    if (this.busy() || !this.valid()) return;
    const id = this.selectedId(); const kind = this.kind();
    const request: Observable<unknown> = kind === 'features'
      ? this.api.saveFeature(id, { name: this.name().trim(), description: this.description() || null, resourceDefinitionId: this.resourceId() })
      : kind === 'feature-rules'
        ? this.api.saveFeatureRule(id, { featureDefinitionId: this.definitionId()!, sourceType: this.sourceType(), sourceId: this.sourceId()!, unlockLevel: this.sourceType() === 'progression' ? null : this.unlockLevel(), progressionThreshold: this.sourceType() === 'progression' ? this.threshold() : null })
        : this.api.saveResourceRule(id, { resourceDefinitionId: this.definitionId()!, sourceType: this.sourceType(), sourceId: this.sourceId()!, unlockLevel: this.unlockLevel()!, maximumBonus: this.bonus()! });
    this.setBusy(true); this.error.set('');
    request.pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.setBusy(false))).subscribe({
      next: () => { this.editing.set(false); this.feedback.set(id === null ? 'Élément créé.' : 'Élément enregistré.'); this.load(); },
      error: (e: { status?: number }) => this.error.set(e.status === 409 ? 'Modification impossible : élément utilisé ou conflit.' : 'Enregistrement impossible. Vérifie les références et les champs.'),
    });
  }
  remove(e: Entry) {
    if (this.busy() || !this.canDiscard() || !window.confirm(`Supprimer « ${this.label(e)} » ?`)) return;
    const request = this.kind() === 'features' ? this.api.deleteFeature(e.id) : this.kind() === 'feature-rules' ? this.api.deleteFeatureRule(e.id) : this.api.deleteResourceRule(e.id);
    this.setBusy(true); this.error.set('');
    request.pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.setBusy(false))).subscribe({
      next: () => { this.editing.set(false); this.selectedId.set(null); this.feedback.set('Élément supprimé.'); this.load(); },
      error: err => this.error.set(err.status === 409 ? 'Cet élément est utilisé et ne peut pas être supprimé.' : 'Suppression impossible.'),
    });
  }
}

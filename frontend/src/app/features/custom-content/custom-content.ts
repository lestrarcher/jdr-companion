import { afterNextRender, Component, computed, DestroyRef, ElementRef, inject, Injector, signal, viewChild } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, map, Observable, Subscription } from 'rxjs';
import { CustomResource, CustomScalingAbility } from '@core/models/custom-content.model';
import { CustomContentApiService } from '@core/services/custom-content-api.service';
import { ResourceEditor } from './resource-editor/resource-editor';

type Category = 'features' | 'resources' | 'feature-rules' | 'resource-rules';
interface CatalogueItem { id: number; title: string; description?: string | null; details: string[]; resource?: CustomResource }
interface CollectionState { status: 'idle' | 'loading' | 'ready' | 'error'; items: CatalogueItem[] }
const emptyCollection = (): CollectionState => ({ status: 'idle', items: [] });

@Component({
  selector: 'app-custom-content',
  imports: [ResourceEditor],
  templateUrl: './custom-content.html',
  styleUrl: './custom-content.scss',
})
export class CustomContent {
  private readonly api = inject(CustomContentApiService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly injector = inject(Injector);
  private readonly resourceEditor = viewChild(ResourceEditor);
  private readonly createButton = viewChild<ElementRef<HTMLButtonElement>>('createResource');
  private editorTrigger: HTMLElement | null = null;
  private readonly pendingCollections: Partial<Record<Category, Subscription>> = {};
  protected readonly editor = signal<{ resource: CustomResource | null } | null>(null);
  protected readonly deletingId = signal<number | null>(null);
  protected readonly busy = computed(() => this.deletingId() !== null || !!this.resourceEditor()?.saving());
  protected readonly feedback = signal<string | null>(null);
  protected readonly mutationError = signal<string | null>(null);
  protected readonly categories: { id: Category; label: string; empty: string }[] = [
    { id: 'features', label: 'Capacités', empty: 'Aucune capacité personnalisée.' },
    { id: 'resources', label: 'Ressources', empty: 'Aucune ressource personnalisée.' },
    { id: 'feature-rules', label: 'Attributions de capacités', empty: 'Aucune attribution de capacité personnalisée.' },
    { id: 'resource-rules', label: 'Règles de ressources', empty: 'Aucune règle de ressource personnalisée.' },
  ];
  protected readonly active = signal<Category>('features');
  private readonly collections = signal<Record<Category, CollectionState>>({
    features: emptyCollection(), resources: emptyCollection(),
    'feature-rules': emptyCollection(), 'resource-rules': emptyCollection(),
  });
  protected readonly current = computed(() => this.collections()[this.active()]);
  protected readonly category = computed(() => this.categories.find(category => category.id === this.active())!);

  constructor() { this.load('features'); }

  protected select(category: Category): void {
    if (category === this.active() || this.busy()) return;
    if (this.resourceEditor() && !this.resourceEditor()!.canDiscard()) return;
    this.editor.set(null);
    this.feedback.set(null); this.mutationError.set(null);
    this.active.set(category);
    if (this.collections()[category].status === 'idle') this.load(category);
  }

  protected retry(): void { this.load(this.active()); }

  protected openEditor(resource: CustomResource | null, event: Event): void {
    if (this.busy() || this.editor()) return;
    this.editorTrigger = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    this.feedback.set(null); this.mutationError.set(null);
    this.editor.set({ resource });
  }

  protected closeEditor(): void {
    this.editor.set(null);
    this.restoreFocus();
  }

  private restoreFocus(): void {
    afterNextRender(() => {
      const target = this.editorTrigger?.isConnected ? this.editorTrigger : this.createButton()?.nativeElement;
      target?.focus();
    }, { injector: this.injector });
  }

  protected resourceSaved(resource: CustomResource): void {
    const created = !this.editor()?.resource;
    const items = this.collections().resources.items.filter(item => item.id !== resource.id);
    items.push(this.resourceItem(resource));
    items.sort((a, b) => a.title.localeCompare(b.title, 'fr') || a.id - b.id);
    this.setCollection('resources', { status: 'ready', items });
    // Related summaries can carry the old resource name; reload only when revisited.
    if (!created && this.editor()?.resource?.name !== resource.name) {
      this.invalidateRelatedCollections();
    }
    this.feedback.set(created ? 'Ressource créée.' : 'Ressource enregistrée.');
    this.closeEditor();
  }

  protected deleteResource(resource: CustomResource, event: Event): void {
    if (this.busy() || this.editor() || !window.confirm(`Supprimer la ressource « ${resource.name} » ?`)) return;
    this.editorTrigger = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    this.deletingId.set(resource.id); this.mutationError.set(null); this.feedback.set(null);
    const remove = () => {
      this.collections.update(collections => ({ ...collections, resources: {
        ...collections.resources, items: collections.resources.items.filter(item => item.id !== resource.id),
      } }));
      this.invalidateRelatedCollections();
      this.restoreFocus();
    };
    this.api.deleteResource(resource.id).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.deletingId.set(null))).subscribe({
      next: () => { remove(); this.feedback.set('Ressource supprimée.'); },
      error: (error: unknown) => {
        if (error instanceof HttpErrorResponse && error.status === 404) {
          remove(); this.feedback.set('Cette ressource n’est plus disponible. Elle a été retirée du catalogue local.');
        } else {
          this.mutationError.set(error instanceof HttpErrorResponse && error.status === 409
            ? 'Cette ressource est utilisée et ne peut pas être supprimée.'
            : 'La ressource n’a pas pu être supprimée. Vous pouvez réessayer.');
        }
      },
    });
  }

  private resourceItem(item: CustomResource): CatalogueItem {
    return { id: item.id, title: item.name, description: item.description, resource: item,
      details: [this.maximumLabel(item), {
        none: 'Aucune recharge automatique', 'short-rest': 'Recharge : repos court', 'long-rest': 'Recharge : repos long',
      }[item.rechargeType]],
    };
  }

  private invalidateRelatedCollections(): void {
    for (const category of ['features', 'resource-rules'] as const) {
      this.pendingCollections[category]?.unsubscribe();
      this.setCollection(category, emptyCollection());
    }
  }

  private load(category: Category): void {
    if (this.collections()[category].status === 'loading') return;
    this.setCollection(category, { status: 'loading', items: [] });
    this.pendingCollections[category] = this.request(category).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: items => this.setCollection(category, { status: 'ready', items }),
      error: () => this.setCollection(category, { status: 'error', items: [] }),
    });
  }

  private setCollection(category: Category, state: CollectionState): void {
    this.collections.update(collections => ({ ...collections, [category]: state }));
  }

  private request(category: Category): Observable<CatalogueItem[]> {
    switch (category) {
      case 'features': return this.api.features().pipe(map(items => items.map(item => ({
        id: item.id, title: item.name, description: item.description,
        details: item.resourceDefinition ? [`Ressource liée : ${item.resourceDefinition.name}`] : [],
      }))));
      case 'resources': return this.api.resources().pipe(map(items => items.map(item => this.resourceItem(item))));
      case 'feature-rules': return this.api.featureRules().pipe(map(items => items.map(item => ({
        id: item.id, title: `${item.featureDefinition.name} → ${item.source.name}`,
        details: [item.source.type === 'progression' ? `Seuil ${item.progressionThreshold}` : `Niveau ${item.unlockLevel}`],
      }))));
      case 'resource-rules': return this.api.resourceRules().pipe(map(items => items.map(item => ({
        id: item.id, title: `${item.resourceDefinition.name} → ${item.source.name}`,
        details: [`Niveau ${item.unlockLevel}`, item.maximumBonus === 0 ? 'Attribue la ressource' : `+${item.maximumBonus} au maximum`],
      }))));
    }
  }

  private maximumLabel(resource: CustomResource): string {
    const abilities: Record<CustomScalingAbility, string> = {
      strength: 'Force', dexterity: 'Dextérité', constitution: 'Constitution',
      intelligence: 'Intelligence', wisdom: 'Sagesse', charisma: 'Charisme',
    };
    const minimum = resource.minimumMaximum > 0 ? ` · minimum ${resource.minimumMaximum}` : '';
    if (resource.maximumType === 'fixed') return `Maximum de base : ${resource.baseMaximum}${minimum}`;
    const factor = resource.maximumType === 'proficiency-bonus' ? 'bonus de maîtrise'
      : `modificateur de ${resource.scalingAbility ? abilities[resource.scalingAbility] : 'caractéristique'}`;
    return `Maximum : ${resource.baseMaximum} + ${resource.multiplier} × ${factor}${minimum}`;
  }
}

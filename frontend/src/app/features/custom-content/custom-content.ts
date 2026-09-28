import { Component, computed, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { map, Observable } from 'rxjs';
import { CustomResource, CustomScalingAbility } from '@core/models/custom-content.model';
import { CustomContentApiService } from '@core/services/custom-content-api.service';

type Category = 'features' | 'resources' | 'feature-rules' | 'resource-rules';
interface CatalogueItem { id: number; title: string; description?: string | null; details: string[] }
interface CollectionState { status: 'idle' | 'loading' | 'ready' | 'error'; items: CatalogueItem[] }
const emptyCollection = (): CollectionState => ({ status: 'idle', items: [] });

@Component({
  selector: 'app-custom-content',
  templateUrl: './custom-content.html',
  styleUrl: './custom-content.scss',
})
export class CustomContent {
  private readonly api = inject(CustomContentApiService);
  private readonly destroyRef = inject(DestroyRef);
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
    this.active.set(category);
    if (this.collections()[category].status === 'idle') this.load(category);
  }

  protected retry(): void { this.load(this.active()); }

  private load(category: Category): void {
    if (this.collections()[category].status === 'loading') return;
    this.setCollection(category, { status: 'loading', items: [] });
    this.request(category).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
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
      case 'resources': return this.api.resources().pipe(map(items => items.map(item => ({
        id: item.id, title: item.name, description: item.description,
        details: [this.maximumLabel(item), {
          none: 'Aucune recharge automatique', 'short-rest': 'Recharge : repos court', 'long-rest': 'Recharge : repos long',
        }[item.rechargeType]],
      }))));
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

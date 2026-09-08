import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  AbilityChoice,
  CharacterFeatureApiService,
  EnumChoice,
  SaveResourcePayload,
  TrackableResourceDefinition,
} from '../../../../core/services/character-feature-api.service';

interface ResourceForm {
  slug: string;
  name: string;
  description: string;
  rechargeType: string;
  maximumType: string;
  baseMaximum: number;
  multiplier: number;
  minimumMaximum: number;
  scalingAbility: string | null;
  custom: boolean;
}

@Component({
  selector: 'app-resource-definition-manager',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './resource-definition-manager.html',
  styleUrl: './resource-definition-manager.scss',
})
export class ResourceDefinitionManager {
  private readonly api = inject(CharacterFeatureApiService);

  protected readonly resources = signal<TrackableResourceDefinition[]>([]);
  protected readonly rechargeTypes = signal<EnumChoice[]>([]);
  protected readonly maximumTypes = signal<EnumChoice[]>([]);
  protected readonly abilities = signal<AbilityChoice[]>([]);

  protected readonly selectedResourceId = signal<number | null>(null);
  protected readonly search = signal('');
  protected readonly rechargeFilter = signal<string>('all');
  protected readonly loading = signal(true);
  protected readonly submitting = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);

  protected form: ResourceForm = this.emptyForm();

  protected readonly selectedResource = computed(() => {
    const selectedId = this.selectedResourceId();

    return this.resources().find(resource =>
      resource.id === selectedId
    ) ?? null;
  });

  protected readonly filteredResources = computed(() => {
    const search = this.normalizeSearch(this.search());
    const rechargeFilter = this.rechargeFilter();

    return this.resources().filter(resource => {
      if (
        rechargeFilter !== 'all' &&
        resource.rechargeType !== rechargeFilter
      ) {
        return false;
      }

      if (!search) {
        return true;
      }

      return this.normalizeSearch(
        `${resource.name} ${resource.slug} ${resource.description ?? ''}`,
      ).includes(search);
    });
  });

  public constructor() {
    this.loadResources();
  }

  protected selectResource(resource: TrackableResourceDefinition): void {
    this.selectedResourceId.set(resource.id);
    this.form = {
      slug: resource.slug,
      name: resource.name,
      description: resource.description ?? '',
      rechargeType: resource.rechargeType,
      maximumType: resource.maximumType,
      baseMaximum: resource.baseMaximum,
      multiplier: resource.multiplier,
      minimumMaximum: resource.minimumMaximum,
      scalingAbility: resource.scalingAbility,
      custom: resource.custom,
    };
    this.clearMessages();
  }

  protected startCreation(): void {
    this.selectedResourceId.set(null);
    this.form = this.emptyForm();
    this.clearMessages();
  }

  protected save(): void {
    if (this.submitting()) {
      return;
    }

    if (
      !this.form.name.trim() ||
      !this.form.slug.trim() ||
      !this.form.rechargeType ||
      !this.form.maximumType
    ) {
      this.error.set(
        'Le nom, le slug, la recharge et le calcul du maximum sont obligatoires.',
      );
      return;
    }

    if (
      this.form.maximumType === 'ability_modifier' &&
      !this.form.scalingAbility
    ) {
      this.error.set(
        'Sélectionne la caractéristique utilisée pour calculer le maximum.',
      );
      return;
    }

    const payload: SaveResourcePayload = {
      slug: this.form.slug.trim(),
      name: this.form.name.trim(),
      description: this.form.description.trim() || null,
      rechargeType: this.form.rechargeType,
      maximumType: this.form.maximumType,
      baseMaximum: Number(this.form.baseMaximum),
      multiplier: Number(this.form.multiplier),
      minimumMaximum: Number(this.form.minimumMaximum),
      scalingAbility: this.form.maximumType === 'ability_modifier'
        ? this.form.scalingAbility
        : null,
      custom: this.form.custom,
    };

    const selectedId = this.selectedResourceId();
    const request = selectedId === null
      ? this.api.createResource(payload)
      : this.api.updateResource(selectedId, payload);

    this.submitting.set(true);
    this.clearMessages();

    request.subscribe({
      next: response => {
        this.replaceResource(response.resource);
        this.selectResource(response.resource);
        this.success.set(
          selectedId === null
            ? `${response.resource.name} a bien été créée.`
            : `${response.resource.name} a bien été mise à jour.`,
        );
        this.submitting.set(false);
      },
      error: error => {
        this.error.set(
          error.error?.error ??
          error.error?.message ??
          'Impossible d’enregistrer cette ressource.',
        );
        this.submitting.set(false);
      },
    });
  }

  protected generateSlug(): void {
    this.form.slug = this.slugify(this.form.name);
  }

  protected updateSearch(value: string): void {
    this.search.set(value);
  }

  protected updateRechargeFilter(value: string): void {
    this.rechargeFilter.set(value);
  }

  protected rechargeLabel(value: string): string {
    return this.rechargeTypes().find(choice =>
      choice.value === value
    )?.label ?? value;
  }

  protected maximumLabel(value: string): string {
    return this.maximumTypes().find(choice =>
      choice.value === value
    )?.label ?? value;
  }

  protected abilityLabel(value: string | null): string {
    if (!value) {
      return 'Aucune';
    }

    const ability = this.abilities().find(choice =>
      choice.value === value
    );

    return ability
      ? `${ability.label} (${ability.abbreviation})`
      : value;
  }

  protected trackResource(
    _: number,
    resource: TrackableResourceDefinition,
  ): number {
    return resource.id;
  }

  private loadResources(): void {
    this.loading.set(true);
    this.error.set(null);

    this.api.getResources().subscribe({
      next: result => {
        this.resources.set(this.sortResources(result.resources));
        this.rechargeTypes.set(result.rechargeTypes);
        this.maximumTypes.set(result.maximumTypes);
        this.abilities.set(result.abilities);
        this.loading.set(false);
      },
      error: error => {
        this.error.set(
          error.error?.error ??
          error.error?.message ??
          'Impossible de charger les ressources.',
        );
        this.loading.set(false);
      },
    });
  }

  private replaceResource(
    savedResource: TrackableResourceDefinition,
  ): void {
    const exists = this.resources().some(resource =>
      resource.id === savedResource.id
    );

    const updatedResources = exists
      ? this.resources().map(resource =>
          resource.id === savedResource.id
            ? savedResource
            : resource,
        )
      : [...this.resources(), savedResource];

    this.resources.set(this.sortResources(updatedResources));
  }

  private sortResources(
    resources: TrackableResourceDefinition[],
  ): TrackableResourceDefinition[] {
    return [...resources].sort((first, second) =>
      first.name.localeCompare(second.name, 'fr'),
    );
  }

  private emptyForm(): ResourceForm {
    return {
      slug: '',
      name: '',
      description: '',
      rechargeType: '',
      maximumType: 'fixed',
      baseMaximum: 1,
      multiplier: 1,
      minimumMaximum: 0,
      scalingAbility: null,
      custom: true,
    };
  }

  private slugify(value: string): string {
    return value
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLocaleLowerCase('fr')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }

  private normalizeSearch(value: string): string {
    return value
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLocaleLowerCase('fr')
      .trim();
  }

  private clearMessages(): void {
    this.error.set(null);
    this.success.set(null);
  }
}

import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { forkJoin } from 'rxjs';
import {
  CharacterFeatureApiService,
  CharacterFeatureDefinition,
  EnumChoice,
  FeatureActivationType,
  SaveFeaturePayload,
  TrackableResourceDefinition,
} from '../../../../core/services/character-feature-api.service';

interface FeatureForm {
  slug: string;
  name: string;
  description: string;
  activationType: FeatureActivationType;
  resourceDefinitionId: number | null;
  visible: boolean;
  custom: boolean;
}

@Component({
  selector: 'app-feature-definition-manager',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './feature-definition-manager.html',
  styleUrl: './feature-definition-manager.scss',
})
export class FeatureDefinitionManager {
  private readonly api = inject(CharacterFeatureApiService);

  protected readonly features = signal<CharacterFeatureDefinition[]>([]);
  protected readonly resources = signal<TrackableResourceDefinition[]>([]);
  protected readonly activationTypes =
    signal<EnumChoice<FeatureActivationType>[]>([]);

  protected readonly selectedFeatureId = signal<number | null>(null);
  protected readonly search = signal('');
  protected readonly activationFilter =
    signal<FeatureActivationType | 'all'>('all');
  protected readonly loading = signal(true);
  protected readonly submitting = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);

  protected form: FeatureForm = this.emptyForm();

  protected readonly selectedFeature = computed(() => {
    const selectedId = this.selectedFeatureId();
    return this.features().find(feature => feature.id === selectedId) ?? null;
  });

  protected readonly filteredFeatures = computed(() => {
    const search = this.normalizeSearch(this.search());
    const activationFilter = this.activationFilter();

    return this.features().filter(feature => {
      if (
        activationFilter !== 'all' &&
        feature.activationType !== activationFilter
      ) {
        return false;
      }

      return !search || this.normalizeSearch(
        `${feature.name} ${feature.slug} ${feature.description ?? ''}`,
      ).includes(search);
    });
  });

  public constructor() {
    this.loadData();
  }

  protected selectFeature(feature: CharacterFeatureDefinition): void {
    this.selectedFeatureId.set(feature.id);
    this.form = {
      slug: feature.slug,
      name: feature.name,
      description: feature.description ?? '',
      activationType: feature.activationType,
      resourceDefinitionId: feature.resourceDefinition?.id ?? null,
      visible: feature.visible,
      custom: feature.custom,
    };
    this.clearMessages();
  }

  protected startCreation(): void {
    this.selectedFeatureId.set(null);
    this.form = this.emptyForm();
    this.clearMessages();
  }

  protected save(): void {
    if (this.submitting()) {
      return;
    }

    if (!this.form.name.trim() || !this.form.slug.trim()) {
      this.error.set('Le nom et le slug sont obligatoires.');
      return;
    }

    const payload: SaveFeaturePayload = {
      slug: this.form.slug.trim(),
      name: this.form.name.trim(),
      description: this.form.description.trim() || null,
      activationType: this.form.activationType,
      resourceDefinitionId: this.form.resourceDefinitionId
        ? Number(this.form.resourceDefinitionId)
        : null,
      visible: this.form.visible,
      custom: this.form.custom,
    };

    const selectedId = this.selectedFeatureId();
    const request = selectedId === null
      ? this.api.createFeature(payload)
      : this.api.updateFeature(selectedId, payload);

    this.submitting.set(true);
    this.clearMessages();

    request.subscribe({
      next: response => {
        this.replaceFeature(response.feature);
        this.selectFeature(response.feature);
        this.success.set(
          selectedId === null
            ? `${response.feature.name} a bien été créée.`
            : `${response.feature.name} a bien été mise à jour.`,
        );
        this.submitting.set(false);
      },
      error: error => {
        this.error.set(
          error.error?.error ??
          error.error?.message ??
          'Impossible d’enregistrer cette capacité.',
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

  protected updateActivationFilter(
    value: FeatureActivationType | 'all',
  ): void {
    this.activationFilter.set(value);
  }

  protected activationLabel(feature: CharacterFeatureDefinition): string {
    return feature.activationLabel ??
      this.activationTypes().find(
        activation => activation.value === feature.activationType,
      )?.label ??
      feature.activationType;
  }

  protected trackFeature(
    _: number,
    feature: CharacterFeatureDefinition,
  ): number {
    return feature.id;
  }

  private loadData(): void {
    this.loading.set(true);
    this.error.set(null);

    forkJoin({
      features: this.api.getFeatures(),
      resources: this.api.getResources(),
    }).subscribe({
      next: result => {
        this.features.set(this.sortFeatures(result.features.features));
        this.activationTypes.set(result.features.activationTypes);
        this.resources.set(
          [...result.resources.resources].sort((first, second) =>
            first.name.localeCompare(second.name, 'fr'),
          ),
        );
        this.loading.set(false);
      },
      error: error => {
        this.error.set(
          error.error?.error ??
          error.error?.message ??
          'Impossible de charger les capacités.',
        );
        this.loading.set(false);
      },
    });
  }

  private replaceFeature(savedFeature: CharacterFeatureDefinition): void {
    const exists = this.features().some(feature => feature.id === savedFeature.id);

    const updatedFeatures = exists
      ? this.features().map(feature =>
          feature.id === savedFeature.id ? savedFeature : feature,
        )
      : [...this.features(), savedFeature];

    this.features.set(this.sortFeatures(updatedFeatures));
  }

  private sortFeatures(
    features: CharacterFeatureDefinition[],
  ): CharacterFeatureDefinition[] {
    return [...features].sort((first, second) =>
      first.name.localeCompare(second.name, 'fr'),
    );
  }

  private emptyForm(): FeatureForm {
    return {
      slug: '',
      name: '',
      description: '',
      activationType: 'passive',
      resourceDefinitionId: null,
      visible: true,
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

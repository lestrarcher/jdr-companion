import { CommonModule } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { forkJoin } from 'rxjs';
import {
  AbilityChoice,
  CharacterFeatureApiService,
  CharacterFeatureDefinition,
  CharacterFeatureRule,
  CreateFeatureRulePayload,
  EnumChoice,
  FeatureActivationType,
  FeatureSourceType,
  SaveFeaturePayload,
  SaveResourcePayload,
  TrackableResourceDefinition,
} from '../../../core/services/character-feature-api.service';
import {
  ClassReference,
  DndReferenceApiService,
  DndReferenceResponse,
  FeatReference,
  SaveSubclassPayload,
  SpellcastingProgression,
  SpellcastingProgressionChoice,
  SubclassReference,
} from '../../../core/services/dnd-reference-api.service';

type ManagerSection = 'subclasses' | 'features' | 'resources' | 'rules';

interface SourceOption {
  id: number;
  name: string;
}

interface SubclassForm {
  classId: number | null;
  slug: string;
  name: string;
  description: string;
  spellcastingProgression: SpellcastingProgression | null;
  custom: boolean;
}

interface FeatureForm {
  slug: string;
  name: string;
  description: string;
  activationType: FeatureActivationType;
  resourceDefinitionId: number | null;
  visible: boolean;
  custom: boolean;
}

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

interface RuleForm {
  featureDefinitionId: number | null;
  sourceType: FeatureSourceType;
  sourceId: number | null;
  unlockLevel: number;
  displayOrder: number;
}

@Component({
  selector: 'app-feature-manager',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './feature-manager.html',
  styleUrl: './feature-manager.scss',
})
export class FeatureManager implements OnInit {
  private readonly referenceApi = inject(DndReferenceApiService);
  private readonly featureApi = inject(CharacterFeatureApiService);

  protected readonly activeSection = signal<ManagerSection>('subclasses');
  protected readonly loading = signal(true);
  protected readonly submitting = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);

  protected readonly reference = signal<DndReferenceResponse | null>(null);
  protected readonly subclasses = signal<SubclassReference[]>([]);
  protected readonly features = signal<CharacterFeatureDefinition[]>([]);
  protected readonly resources = signal<TrackableResourceDefinition[]>([]);
  protected readonly rules = signal<CharacterFeatureRule[]>([]);

  protected readonly activationTypes = signal<EnumChoice<FeatureActivationType>[]>([]);
  protected readonly rechargeTypes = signal<EnumChoice[]>([]);
  protected readonly maximumTypes = signal<EnumChoice[]>([]);
  protected readonly abilities = signal<AbilityChoice[]>([]);
  protected readonly spellcastingProgressions = signal<SpellcastingProgressionChoice[]>([]);

  protected readonly editedSubclassId = signal<number | null>(null);
  protected readonly editedFeatureId = signal<number | null>(null);
  protected readonly editedResourceId = signal<number | null>(null);
  protected readonly editedRuleId = signal<number | null>(null);

  protected subclassForm: SubclassForm = this.emptySubclassForm();
  protected featureForm: FeatureForm = this.emptyFeatureForm();
  protected resourceForm: ResourceForm = this.emptyResourceForm();
  protected ruleForm: RuleForm = this.emptyRuleForm();

  protected featureSearch = '';
  protected ruleFilterSearch = '';
  protected ruleFilterSourceType: FeatureSourceType | 'all' = 'all';
  protected ruleFilterSourceId: number | null = null;

  protected readonly classes = computed<ClassReference[]>(
    () => this.reference()?.classes ?? [],
  );

  protected readonly races = computed(() => this.reference()?.races ?? []);
  protected readonly feats = computed<FeatReference[]>(() => this.reference()?.feats ?? []);

  protected sourceOptions(): SourceOption[] {
    switch (this.ruleForm.sourceType) {
      case 'class':
        return this.classes().map(item => ({ id: item.id, name: item.name }));
      case 'subclass':
        return this.subclasses().map(item => ({
          id: item.id,
          name: `${this.className(item.classId)} — ${item.name}`,
        }));
      case 'race':
        return this.races().map(item => ({ id: item.id, name: item.name }));
      case 'feat':
        return this.feats().map(item => ({ id: item.id, name: item.name }));
    }
  }

  protected filteredFeatures(): CharacterFeatureDefinition[] {
    const search = this.normalizeSearch(this.featureSearch);

    if (!search) {
      return this.features();
    }

    return this.features().filter(feature =>
      this.normalizeSearch(`${feature.name} ${feature.slug}`).includes(search),
    );
  }

  protected filteredRules(): CharacterFeatureRule[] {
    const search = this.normalizeSearch(this.ruleFilterSearch);

    return this.rules()
      .filter(rule => {
        if (
          this.ruleFilterSourceType !== 'all'
          && rule.sourceType !== this.ruleFilterSourceType
        ) {
          return false;
        }

        if (
          this.ruleFilterSourceId !== null
          && rule.sourceId !== this.ruleFilterSourceId
        ) {
          return false;
        }

        if (!search) {
          return true;
        }

        return this.normalizeSearch(
          `${rule.feature.name} ${rule.feature.slug} ${rule.sourceName}`,
        ).includes(search);
      })
      .sort((first, second) => {
        const sourceComparison = first.sourceName.localeCompare(
          second.sourceName,
          'fr',
        );

        if (sourceComparison !== 0) {
          return sourceComparison;
        }

        if (first.unlockLevel !== second.unlockLevel) {
          return first.unlockLevel - second.unlockLevel;
        }

        return first.feature.name.localeCompare(second.feature.name, 'fr');
      });
  }

  protected ruleFilterSources(): SourceOption[] {
    if (this.ruleFilterSourceType === 'all') {
      return [];
    }

    const sources = new Map<number, string>();

    for (const rule of this.rules()) {
      if (rule.sourceType === this.ruleFilterSourceType) {
        sources.set(rule.sourceId, rule.sourceName);
      }
    }

    return Array.from(sources, ([id, name]) => ({ id, name }))
      .sort((first, second) => first.name.localeCompare(second.name, 'fr'));
  }

  protected ruleFilterSourceTypeChanged(): void {
    this.ruleFilterSourceId = null;
  }

  protected resetRuleFilters(): void {
    this.ruleFilterSearch = '';
    this.ruleFilterSourceType = 'all';
    this.ruleFilterSourceId = null;
  }

  protected featureSelected(): void {
    this.featureSearch = '';
  }

  ngOnInit(): void {
    this.loadAll();
  }

  protected selectSection(section: ManagerSection): void {
    this.activeSection.set(section);
    this.clearMessages();
  }

  protected loadAll(): void {
    this.loading.set(true);
    this.error.set(null);

    forkJoin({
      reference: this.referenceApi.getReference(),
      subclasses: this.referenceApi.getSubclasses(),
      features: this.featureApi.getFeatures(),
      resources: this.featureApi.getResources(),
      rules: this.featureApi.getRules(),
    }).subscribe({
      next: result => {
        this.reference.set(result.reference);
        this.subclasses.set(result.subclasses.subclasses);
        this.spellcastingProgressions.set(result.subclasses.spellcastingProgressions);
        this.features.set(result.features.features);
        this.activationTypes.set(result.features.activationTypes);
        this.resources.set(result.resources.resources);
        this.rechargeTypes.set(result.resources.rechargeTypes);
        this.maximumTypes.set(result.resources.maximumTypes);
        this.abilities.set(result.resources.abilities);
        this.rules.set(result.rules.rules);
        this.loading.set(false);
      },
      error: error => {
        this.error.set(this.errorMessage(error));
        this.loading.set(false);
      },
    });
  }

  protected saveSubclass(): void {
    if (
      !this.subclassForm.classId
      || !this.subclassForm.name.trim()
      || !this.subclassForm.slug.trim()
    ) {
      this.error.set('La classe, le nom et le slug sont obligatoires.');
      return;
    }

    const payload: SaveSubclassPayload = {
      classId: this.subclassForm.classId,
      slug: this.subclassForm.slug.trim(),
      name: this.subclassForm.name.trim(),
      description: this.nullableString(this.subclassForm.description),
      spellcastingProgression: this.subclassForm.spellcastingProgression,
      custom: this.subclassForm.custom,
    };

    const request = this.editedSubclassId() === null
      ? this.referenceApi.createSubclass(payload)
      : this.referenceApi.updateSubclass(this.editedSubclassId()!, payload);

    this.startSubmission();

    request.subscribe({
      next: response => {
        const editedId = this.editedSubclassId();

        this.subclasses.update(items => editedId === null
          ? [...items, response.subclass].sort(this.sortByName)
          : items
              .map(item => item.id === editedId ? response.subclass : item)
              .sort(this.sortByName),
        );

        this.finishSubmission(
          editedId === null ? 'Sous-classe créée.' : 'Sous-classe modifiée.',
        );
        this.cancelSubclassEdition();
      },
      error: error => this.failSubmission(error),
    });
  }

  protected editSubclass(subclass: SubclassReference): void {
    this.editedSubclassId.set(subclass.id);
    this.subclassForm = {
      classId: subclass.classId,
      slug: subclass.slug,
      name: subclass.name,
      description: subclass.description ?? '',
      spellcastingProgression: subclass.spellcastingProgression,
      custom: subclass.custom ?? false,
    };
    this.clearMessages();
  }

  protected cancelSubclassEdition(): void {
    this.editedSubclassId.set(null);
    this.subclassForm = this.emptySubclassForm();
  }

  protected saveFeature(): void {
    if (!this.featureForm.name.trim() || !this.featureForm.slug.trim()) {
      this.error.set('Le nom et le slug sont obligatoires.');
      return;
    }

    const payload: SaveFeaturePayload = {
      slug: this.featureForm.slug.trim(),
      name: this.featureForm.name.trim(),
      description: this.nullableString(this.featureForm.description),
      activationType: this.featureForm.activationType,
      resourceDefinitionId: this.featureForm.resourceDefinitionId,
      visible: this.featureForm.visible,
      custom: this.featureForm.custom,
    };

    const request = this.editedFeatureId() === null
      ? this.featureApi.createFeature(payload)
      : this.featureApi.updateFeature(this.editedFeatureId()!, payload);

    this.startSubmission();

    request.subscribe({
      next: response => {
        const editedId = this.editedFeatureId();

        this.features.update(items => editedId === null
          ? [...items, response.feature].sort(this.sortByName)
          : items
              .map(item => item.id === editedId ? response.feature : item)
              .sort(this.sortByName),
        );

        this.finishSubmission(
          editedId === null ? 'Capacité créée.' : 'Capacité modifiée.',
        );
        this.cancelFeatureEdition();
      },
      error: error => this.failSubmission(error),
    });
  }

  protected editFeature(feature: CharacterFeatureDefinition): void {
    this.editedFeatureId.set(feature.id);
    this.featureForm = {
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

  protected cancelFeatureEdition(): void {
    this.editedFeatureId.set(null);
    this.featureForm = this.emptyFeatureForm();
  }

  protected saveResource(): void {
    if (
      !this.resourceForm.name.trim()
      || !this.resourceForm.slug.trim()
      || !this.resourceForm.rechargeType
      || !this.resourceForm.maximumType
    ) {
      this.error.set('Le nom, le slug, la recharge et le maximum sont obligatoires.');
      return;
    }

    const payload: SaveResourcePayload = {
      slug: this.resourceForm.slug.trim(),
      name: this.resourceForm.name.trim(),
      description: this.nullableString(this.resourceForm.description),
      rechargeType: this.resourceForm.rechargeType,
      maximumType: this.resourceForm.maximumType,
      baseMaximum: this.resourceForm.baseMaximum,
      multiplier: this.resourceForm.multiplier,
      minimumMaximum: this.resourceForm.minimumMaximum,
      scalingAbility: this.resourceForm.maximumType === 'ability_modifier'
        ? this.resourceForm.scalingAbility
        : null,
      custom: this.resourceForm.custom,
    };

    const request = this.editedResourceId() === null
      ? this.featureApi.createResource(payload)
      : this.featureApi.updateResource(this.editedResourceId()!, payload);

    this.startSubmission();

    request.subscribe({
      next: response => {
        const editedId = this.editedResourceId();

        this.resources.update(items => editedId === null
          ? [...items, response.resource].sort(this.sortByName)
          : items
              .map(item => item.id === editedId ? response.resource : item)
              .sort(this.sortByName),
        );

        this.finishSubmission(
          editedId === null ? 'Ressource créée.' : 'Ressource modifiée.',
        );
        this.cancelResourceEdition();
      },
      error: error => this.failSubmission(error),
    });
  }

  protected editResource(resource: TrackableResourceDefinition): void {
    this.editedResourceId.set(resource.id);
    this.resourceForm = {
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

  protected cancelResourceEdition(): void {
    this.editedResourceId.set(null);
    this.resourceForm = this.emptyResourceForm();
  }

  protected saveRule(): void {
    if (!this.ruleForm.featureDefinitionId || !this.ruleForm.sourceId) {
      this.error.set('La capacité et sa source sont obligatoires.');
      return;
    }

    const editedId = this.editedRuleId();

    if (editedId !== null) {
      this.startSubmission();

      this.featureApi.updateRule(editedId, {
        unlockLevel: this.ruleForm.unlockLevel,
        displayOrder: this.ruleForm.displayOrder,
      }).subscribe({
        next: response => {
          this.rules.update(items => items.map(
            item => item.id === editedId ? response.rule : item,
          ));
          this.finishSubmission('Attribution modifiée.');
          this.cancelRuleEdition();
        },
        error: error => this.failSubmission(error),
      });

      return;
    }

    const payload: CreateFeatureRulePayload = {
      featureDefinitionId: this.ruleForm.featureDefinitionId,
      sourceType: this.ruleForm.sourceType,
      sourceId: this.ruleForm.sourceId,
      unlockLevel: this.ruleForm.unlockLevel,
      displayOrder: this.ruleForm.displayOrder,
    };

    this.startSubmission();

    this.featureApi.createRule(payload).subscribe({
      next: response => {
        this.rules.update(items => [...items, response.rule]);
        this.featureSearch = '';
        this.finishSubmission('Capacité attribuée.');
        this.cancelRuleEdition();
      },
      error: error => this.failSubmission(error),
    });
  }

  protected editRule(rule: CharacterFeatureRule): void {
    this.editedRuleId.set(rule.id);
    this.ruleForm = {
      featureDefinitionId: rule.feature.id,
      sourceType: rule.sourceType,
      sourceId: rule.sourceId,
      unlockLevel: rule.unlockLevel,
      displayOrder: rule.displayOrder,
    };
    this.clearMessages();
  }

  protected cancelRuleEdition(): void {
    this.editedRuleId.set(null);
    this.ruleForm = this.emptyRuleForm();
  }

  protected deleteRule(rule: CharacterFeatureRule): void {
    if (!confirm(`Retirer « ${rule.feature.name} » de « ${rule.sourceName} » ?`)) {
      return;
    }

    this.startSubmission();

    this.featureApi.deleteRule(rule.id).subscribe({
      next: () => {
        this.rules.update(items => items.filter(item => item.id !== rule.id));

        if (this.ruleFilterSourceId === rule.sourceId) {
          const sourceStillExists = this.rules().some(
            item =>
              item.sourceType === rule.sourceType
              && item.sourceId === rule.sourceId,
          );

          if (!sourceStillExists) {
            this.ruleFilterSourceId = null;
          }
        }

        this.finishSubmission('Attribution supprimée.');
        this.cancelRuleEdition();
      },
      error: error => this.failSubmission(error),
    });
  }

  protected sourceTypeChanged(): void {
    this.ruleForm.sourceId = null;
  }

  protected generateSubclassSlug(): void {
    this.subclassForm.slug = this.slugify(this.subclassForm.name);
  }

  protected generateFeatureSlug(): void {
    this.featureForm.slug = this.slugify(this.featureForm.name);
  }

  protected generateResourceSlug(): void {
    this.resourceForm.slug = this.slugify(this.resourceForm.name);
  }

  protected className(classId: number): string {
    return this.classes().find(item => item.id === classId)?.name ?? 'Classe inconnue';
  }

  private emptySubclassForm(): SubclassForm {
    return {
      classId: null,
      slug: '',
      name: '',
      description: '',
      spellcastingProgression: null,
      custom: true,
    };
  }

  private emptyFeatureForm(): FeatureForm {
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

  private emptyResourceForm(): ResourceForm {
    return {
      slug: '',
      name: '',
      description: '',
      rechargeType: '',
      maximumType: 'fixed',
      baseMaximum: 0,
      multiplier: 1,
      minimumMaximum: 0,
      scalingAbility: null,
      custom: true,
    };
  }

  private emptyRuleForm(): RuleForm {
    return {
      featureDefinitionId: null,
      sourceType: 'class',
      sourceId: null,
      unlockLevel: 1,
      displayOrder: 0,
    };
  }

  private startSubmission(): void {
    this.submitting.set(true);
    this.clearMessages();
  }

  private finishSubmission(message: string): void {
    this.submitting.set(false);
    this.success.set(message);
  }

  private failSubmission(error: unknown): void {
    this.submitting.set(false);
    this.error.set(this.errorMessage(error));
  }

  private clearMessages(): void {
    this.error.set(null);
    this.success.set(null);
  }

  private errorMessage(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
      return error.error?.message ?? 'La requête a échoué.';
    }

    return 'Une erreur inattendue est survenue.';
  }

  private nullableString(value: string): string | null {
    const trimmed = value.trim();
    return trimmed !== '' ? trimmed : null;
  }

  private slugify(value: string): string {
    return value
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }

  private normalizeSearch(value: string): string {
    return value
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .trim();
  }

  private readonly sortByName = <T extends { name: string }>(a: T, b: T): number =>
    a.name.localeCompare(b.name, 'fr');
}

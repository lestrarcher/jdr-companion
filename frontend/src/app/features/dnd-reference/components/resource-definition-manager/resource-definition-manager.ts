import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { forkJoin } from 'rxjs';
import {
  AbilityChoice,
  CharacterFeatureApiService,
  CreateResourceRulePayload,
  EnumChoice,
  ResourceRuleSourceType,
  SaveResourcePayload,
  TrackableResourceDefinition,
  TrackableResourceRule,
} from '../../../../core/services/character-feature-api.service';
import { ClassReference, DndReferenceApiService, FeatReference, RaceReference, SubclassReference } from '../../../../core/services/dnd-reference-api.service';

interface SourceOption {
  id: number;
  name: string;
}

interface ResourceRuleForm {
  sourceType: ResourceRuleSourceType;
  sourceId: number | null;
  unlockLevel: number;
  maximumOverride: number | null;
  maximumBonus: number;
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

@Component({
  selector: 'app-resource-definition-manager',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './resource-definition-manager.html',
  styleUrl: './resource-definition-manager.scss',
})
export class ResourceDefinitionManager {
  private readonly api = inject(CharacterFeatureApiService);
  private readonly referenceApi = inject(DndReferenceApiService);

  protected readonly resources = signal<TrackableResourceDefinition[]>([]);
  protected readonly rechargeTypes = signal<EnumChoice[]>([]);
  protected readonly maximumTypes = signal<EnumChoice[]>([]);
  protected readonly abilities = signal<AbilityChoice[]>([]);
  protected readonly classes = signal<ClassReference[]>([]);
  protected readonly races = signal<RaceReference[]>([]);
  protected readonly feats = signal<FeatReference[]>([]);
  protected readonly subclasses = signal<SubclassReference[]>([]);
  protected readonly resourceRules = signal<TrackableResourceRule[]>([]);

  protected readonly maximumRuleGroups = computed(() => {
    const groups = new Map<string, TrackableResourceRule[]>();
    for (const rule of this.resourceRules()) {
      if (rule.maximumOverride === null) continue;
      const key = `${rule.sourceType}:${rule.sourceId}`;
      const rules = groups.get(key) ?? [];
      rules.push(rule);
      groups.set(key, rules);
    }
    return Array.from(groups, ([key, rules]) => {
      rules.sort((first, second) => first.unlockLevel - second.unlockLevel);
      return { key, sourceName: rules[0].sourceName, rules, minimumLevel: rules[0].unlockLevel, maximumLevel: rules[rules.length - 1].unlockLevel };
    });
  });

  protected readonly selectedResourceId = signal<number | null>(null);
  protected readonly search = signal('');
  protected readonly rechargeFilter = signal<string>('all');
  protected readonly loading = signal(true);
  protected readonly submitting = signal(false);
  protected readonly ruleSubmitting = signal(false);
  protected readonly selectedResourceRuleId = signal<number | null>(null);
  protected readonly ruleEditorOpen = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);

  protected form: ResourceForm = this.emptyForm();
  protected ruleForm: ResourceRuleForm = this.emptyRuleForm();

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
    this.selectedResourceRuleId.set(null);
    this.ruleEditorOpen.set(false);
    this.ruleForm = this.emptyRuleForm();
    this.loadResourceRules(resource.id);
    this.clearMessages();
  }

  protected startCreation(): void {
    this.selectedResourceId.set(null);
    this.form = this.emptyForm();
    this.resourceRules.set([]);
    this.selectedResourceRuleId.set(null);
    this.ruleEditorOpen.set(false);
    this.ruleForm = this.emptyRuleForm();
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

  protected formSourceOptions(): SourceOption[] {
    return this.sourceOptions(this.ruleForm.sourceType);
  }

  protected ruleSourceTypeChanged(): void {
    this.ruleForm.sourceId = null;
  }

  protected sourceTypeLabel(sourceType: ResourceRuleSourceType): string {
    return { class: 'Classe', subclass: 'Sous-classe', race: 'Race', feat: 'Don' }[sourceType];
  }

  protected selectResourceRule(rule: TrackableResourceRule): void {
    this.selectedResourceRuleId.set(rule.id);
    this.ruleEditorOpen.set(true);
    this.ruleForm = { sourceType: rule.sourceType, sourceId: rule.sourceId, unlockLevel: rule.unlockLevel, maximumOverride: rule.maximumOverride, maximumBonus: rule.maximumBonus };
    this.clearMessages();
  }

  protected startResourceRuleCreation(): void {
    this.selectedResourceRuleId.set(null);
    this.ruleEditorOpen.set(true);
    this.ruleForm = this.emptyRuleForm();


    this.clearMessages();
  }

  protected closeResourceRuleEditor(): void {
    this.selectedResourceRuleId.set(null);
    this.ruleEditorOpen.set(false);
    this.ruleForm = this.emptyRuleForm();
  }

  protected saveResourceRule(): void {
    const resource = this.selectedResource();
    if (!resource || this.ruleSubmitting()) return;

    if (this.ruleForm.sourceId === null) {
      this.error.set('La source de la règle est obligatoire.');
      return;
    }

    const selectedRuleId = this.selectedResourceRuleId();
    this.ruleSubmitting.set(true);
    this.clearMessages();

    if (selectedRuleId !== null) {
      this.api.updateResourceRule(selectedRuleId, {
        maximumOverride: this.ruleForm.maximumOverride === null ? null : Number(this.ruleForm.maximumOverride),
        maximumBonus: Number(this.ruleForm.maximumBonus),
      }).subscribe({
        next: response => {
          this.replaceResourceRule(response.rule);
          this.selectResourceRule(response.rule);
          this.success.set('La règle de ressource a bien été mise à jour.');
          this.ruleSubmitting.set(false);
        },
        error: error => this.handleRuleSaveError(error),
      });
      return;
    }

    const payload: CreateResourceRulePayload = {
      sourceType: this.ruleForm.sourceType,
      sourceId: Number(this.ruleForm.sourceId),
      unlockLevel: Number(this.ruleForm.unlockLevel),
      maximumOverride: this.ruleForm.maximumOverride === null ? null : Number(this.ruleForm.maximumOverride),
      maximumBonus: Number(this.ruleForm.maximumBonus),
    };

    this.api.createResourceRule(resource.id, payload).subscribe({
      next: response => {
        this.resourceRules.update(rules => [...rules, response.rule]);
        this.selectResourceRule(response.rule);
        this.success.set('La règle de ressource a bien été créée.');
        this.ruleSubmitting.set(false);
      },
      error: error => this.handleRuleSaveError(error),
    });
  }

  protected deleteResourceRule(rule: TrackableResourceRule): void {
    const confirmed = window.confirm(`Supprimer la règle « ${rule.sourceName} » ?`);
    if (!confirmed || this.ruleSubmitting()) return;

    this.ruleSubmitting.set(true);
    this.clearMessages();

    this.api.deleteResourceRule(rule.id).subscribe({
      next: () => {
        this.resourceRules.update(rules => rules.filter(existingRule => existingRule.id !== rule.id));
        if (this.selectedResourceRuleId() === rule.id) this.startResourceRuleCreation();
        this.success.set('La règle de ressource a bien été supprimée.');
        this.ruleSubmitting.set(false);
      },
      error: error => this.handleRuleSaveError(error),
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

    forkJoin({
      resources: this.api.getResources(),
      reference: this.referenceApi.getReference(),
      subclasses: this.referenceApi.getSubclasses(),
    }).subscribe({
      next: result => {
        this.resources.set(this.sortResources(result.resources.resources));
        this.rechargeTypes.set(result.resources.rechargeTypes);
        this.maximumTypes.set(result.resources.maximumTypes);
        this.abilities.set(result.resources.abilities);
        this.classes.set(this.sortByName(result.reference.classes));
        this.races.set(this.sortByName(result.reference.races));
        this.feats.set(this.sortByName(result.reference.feats));
        this.subclasses.set(this.sortByName(result.subclasses.subclasses));
        this.loading.set(false);
      },
      error: error => {
        this.error.set(error.error?.error ?? error.error?.message ?? 'Impossible de charger les ressources.');
        this.loading.set(false);
      },
    });
  }

  private loadResourceRules(resourceId: number): void {
    this.api.getResourceRules(resourceId).subscribe({
      next: result => this.resourceRules.set(result.rules),
      error: error => this.error.set(error.error?.error ?? error.error?.message ?? 'Impossible de charger les règles de la ressource.'),
    });
  }

  private sourceOptions(sourceType: ResourceRuleSourceType): SourceOption[] {
    switch (sourceType) {
      case 'class': return this.classes().map(item => ({ id: item.id, name: item.name }));
      case 'subclass': return this.subclasses().map(item => ({ id: item.id, name: `${this.className(item.classId)} — ${item.name}` }));
      case 'race': return this.races().map(item => ({ id: item.id, name: item.name }));
      case 'feat': return this.feats().map(item => ({ id: item.id, name: item.name }));
    }
  }

  private className(classId: number): string {
    return this.classes().find(characterClass => characterClass.id === classId)?.name ?? 'Classe inconnue';
  }

  private replaceResourceRule(savedRule: TrackableResourceRule): void {
    this.resourceRules.update(rules => rules.map(rule => rule.id === savedRule.id ? savedRule : rule));
  }

  private handleRuleSaveError(error: { error?: { error?: string; message?: string } }): void {
    this.error.set(error.error?.error ?? error.error?.message ?? 'Impossible d’enregistrer cette règle de ressource.');
    this.ruleSubmitting.set(false);
  }

  private sortByName<T extends { name: string }>(items: T[]): T[] {
    return [...items].sort((first, second) => first.name.localeCompare(second.name, 'fr'));
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

  private emptyRuleForm(): ResourceRuleForm {
    return { sourceType: 'class', sourceId: null, unlockLevel: 1, maximumOverride: null, maximumBonus: 0 };
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

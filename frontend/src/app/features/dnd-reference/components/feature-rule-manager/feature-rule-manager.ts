import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { forkJoin } from 'rxjs';
import {
  CharacterFeatureApiService,
  CharacterFeatureDefinition,
  CharacterFeatureRule,
  CreateFeatureRulePayload,
  FeatureSourceType,
} from '../../../../core/services/character-feature-api.service';
import {
  ClassReference,
  DndReferenceApiService,
  FeatReference,
  ProgressionReference,
  RaceReference,
  SubclassReference,
} from '../../../../core/services/dnd-reference-api.service';

interface SourceOption {
  id: number;
  name: string;
}

interface RuleForm {
  featureDefinitionId: number | null;
  sourceType: FeatureSourceType;
  sourceId: number | null;
  unlockLevel: number;
  progressionThreshold: number | null;
  displayOrder: number;
}

@Component({
  selector: 'app-feature-rule-manager',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './feature-rule-manager.html',
  styleUrl: './feature-rule-manager.scss',
})
export class FeatureRuleManager {
  private readonly featureApi = inject(CharacterFeatureApiService);
  private readonly referenceApi = inject(DndReferenceApiService);

  protected readonly classes = signal<ClassReference[]>([]);
  protected readonly races = signal<RaceReference[]>([]);
  protected readonly feats = signal<FeatReference[]>([]);
  protected readonly subclasses = signal<SubclassReference[]>([]);
  protected readonly features = signal<CharacterFeatureDefinition[]>([]);
  protected readonly rules = signal<CharacterFeatureRule[]>([]);
  protected readonly progressions = signal<ProgressionReference[]>([]);

  protected readonly selectedRuleId = signal<number | null>(null);
  protected readonly featureSearch = signal('');
  protected readonly ruleSearch = signal('');
  protected readonly sourceTypeFilter =
    signal<FeatureSourceType | 'all'>('all');
  protected readonly sourceIdFilter = signal<number | null>(null);
  protected readonly loading = signal(true);
  protected readonly submitting = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);

  protected form: RuleForm = this.emptyForm();

  protected readonly selectedRule = computed(() => {
    const selectedId = this.selectedRuleId();
    return this.rules().find(rule => rule.id === selectedId) ?? null;
  });

  protected readonly filteredFeatures = computed(() => {
    const search = this.normalizeSearch(this.featureSearch());

    if (!search) {
      return this.features();
    }

    return this.features().filter(feature =>
      this.normalizeSearch(
        `${feature.name} ${feature.slug} ${feature.activationLabel}`,
      ).includes(search),
    );
  });

  protected readonly filteredRules = computed(() => {
    const search = this.normalizeSearch(this.ruleSearch());
    const sourceType = this.sourceTypeFilter();
    const sourceId = this.sourceIdFilter();

    return this.rules()
      .filter(rule => {
        if (sourceType !== 'all' && rule.sourceType !== sourceType) {
          return false;
        }

        if (sourceId !== null && rule.sourceId !== sourceId) {
          return false;
        }

        return !search || this.normalizeSearch(
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
  });

  protected formSourceOptions(): SourceOption[] {
    return this.sourceOptions(this.form.sourceType);
  }

  protected formStages() {
    return [...(this.progressions().find(
      progression => progression.id === this.form.sourceId,
    )?.stages ?? [])].sort((a, b) => a.minimumValue - b.minimumValue);
  }

  protected hasMatchingStage(): boolean {
    return this.formStages().some(
      stage => stage.minimumValue === this.form.progressionThreshold,
    );
  }

  protected sourceChanged(): void {
    this.form.progressionThreshold = null;
  }

  protected readonly filterSourceOptions = computed(() => {
    const sourceType = this.sourceTypeFilter();

    if (sourceType === 'all') {
      return [];
    }

    const sources = new Map<number, string>();

    for (const rule of this.rules()) {
      if (rule.sourceType === sourceType) {
        sources.set(rule.sourceId, rule.sourceName);
      }
    }

    return Array.from(sources, ([id, name]) => ({ id, name }))
      .sort((first, second) => first.name.localeCompare(second.name, 'fr'));
  });

  public constructor() {
    this.loadData();
  }

  protected selectRule(rule: CharacterFeatureRule): void {
    this.selectedRuleId.set(rule.id);
    this.form = {
      featureDefinitionId: rule.feature.id,
      sourceType: rule.sourceType,
      sourceId: rule.sourceId,
      unlockLevel: rule.unlockLevel,
      progressionThreshold:
        rule.progressionThreshold ?? null,
      displayOrder: rule.displayOrder,
    };
    this.featureSearch.set('');
    this.clearMessages();
  }

  protected startCreation(): void {
    this.selectedRuleId.set(null);
    this.form = this.emptyForm();
    this.featureSearch.set('');
    this.clearMessages();
  }

  protected save(): void {
    if (this.submitting()) {
      return;
    }

    if (
      this.form.featureDefinitionId === null ||
      this.form.sourceId === null
    ) {
      this.error.set('La capacité et sa source sont obligatoires.');
      return;
    }

    const selectedId = this.selectedRuleId();
    if (this.form.sourceType === 'progression' && !this.hasMatchingStage()) {
      this.error.set('Sélectionnez une phase : le seuil enregistré sera sa valeur minimale.');
      return;
    }
    this.submitting.set(true);
    this.clearMessages();

    if (selectedId !== null) {
      this.featureApi.updateRule(selectedId, {
        ...(this.form.sourceType === 'progression'
          ? {
              progressionThreshold:
                Number(this.form.progressionThreshold),
            }
          : {
              unlockLevel: Number(this.form.unlockLevel),
            }),
        displayOrder: Number(this.form.displayOrder),
      }).subscribe({
        next: response => {
          this.replaceRule(response.rule);
          this.selectRule(response.rule);
          this.success.set('L’attribution a bien été mise à jour.');
          this.submitting.set(false);
        },
        error: error => this.handleSaveError(error),
      });

      return;
    }

    const payload: CreateFeatureRulePayload = {
      featureDefinitionId: Number(this.form.featureDefinitionId),
      sourceType: this.form.sourceType,
      sourceId: Number(this.form.sourceId),
      unlockLevel: Number(this.form.unlockLevel),
      displayOrder: Number(this.form.displayOrder),
    };

    if (this.form.sourceType === 'progression') {
      payload.progressionThreshold =
        Number(this.form.progressionThreshold);
    }

    this.featureApi.createRule(payload).subscribe({
      next: response => {
        this.rules.update(rules => [...rules, response.rule]);
        this.selectRule(response.rule);
        this.success.set('La capacité a bien été attribuée.');
        this.submitting.set(false);
      },
      error: error => this.handleSaveError(error),
    });
  }

  protected deleteRule(rule: CharacterFeatureRule): void {
    const confirmed = window.confirm(
      `Retirer « ${rule.feature.name} » de « ${rule.sourceName} » ?`,
    );

    if (!confirmed || this.submitting()) {
      return;
    }

    this.submitting.set(true);
    this.clearMessages();

    this.featureApi.deleteRule(rule.id).subscribe({
      next: () => {
        this.rules.update(rules =>
          rules.filter(existingRule => existingRule.id !== rule.id),
        );

        if (this.selectedRuleId() === rule.id) {
          this.startCreation();
        }

        this.removeEmptySourceFilter(rule);
        this.success.set('L’attribution a bien été supprimée.');
        this.submitting.set(false);
      },
      error: error => this.handleSaveError(error),
    });
  }

  protected sourceTypeChanged(): void {
    this.form.sourceId = null;
    this.sourceChanged();
  }

  protected sourceTypeFilterChanged(
    sourceType: FeatureSourceType | 'all',
  ): void {
    this.sourceTypeFilter.set(sourceType);
    this.sourceIdFilter.set(null);
  }

  protected featureSelected(): void {
    this.featureSearch.set('');
  }

  protected resetFilters(): void {
    this.ruleSearch.set('');
    this.sourceTypeFilter.set('all');
    this.sourceIdFilter.set(null);
  }

  protected sourceTypeLabel(sourceType: FeatureSourceType): string {
    return {
      class: 'Classe',
      subclass: 'Sous-classe',
      race: 'Race',
      feat: 'Don',
      progression: 'Progression',
    }[sourceType];
  }

  protected className(classId: number): string {
    return this.classes().find(characterClass =>
      characterClass.id === classId
    )?.name ?? 'Classe inconnue';
  }

  protected trackRule(_: number, rule: CharacterFeatureRule): number {
    return rule.id;
  }

  private loadData(): void {
    this.loading.set(true);
    this.error.set(null);

    forkJoin({
      reference: this.referenceApi.getReference(),
      subclasses: this.referenceApi.getSubclasses(),
      features: this.featureApi.getFeatures(),
      rules: this.featureApi.getRules(),
      progressions: this.referenceApi.getProgressions(),
    }).subscribe({
      next: result => {
        this.classes.set(this.sortByName(result.reference.classes));
        this.races.set(this.sortByName(result.reference.races));
        this.feats.set(this.sortByName(result.reference.feats));
        this.progressions.set(this.sortByName(result.progressions.progressions));
        this.subclasses.set(
          this.sortByName(result.subclasses.subclasses),
        );
        this.features.set(this.sortByName(result.features.features));
        this.rules.set(result.rules.rules);
        this.loading.set(false);
      },
      error: error => {
        this.error.set(
          error.error?.error ??
          error.error?.message ??
          'Impossible de charger les attributions.',
        );
        this.loading.set(false);
      },
    });
  }

  private sourceOptions(sourceType: FeatureSourceType): SourceOption[] {
    switch (sourceType) {
      case 'class':
        return this.classes().map(item => ({
          id: item.id,
          name: item.name,
        }));

      case 'subclass':
        return this.subclasses().map(item => ({
          id: item.id,
          name: `${this.className(item.classId)} — ${item.name}`,
        }));

      case 'race':
        return this.races().map(item => ({
          id: item.id,
          name: item.name,
        }));

      case 'feat':
        return this.feats().map(item => ({
          id: item.id,
          name: item.name,
        }));

      case 'progression':
        return this.progressions().map(item => ({
          id: item.id,
          name: item.name,
        }));
    }
  }

  private replaceRule(savedRule: CharacterFeatureRule): void {
    this.rules.update(rules =>
      rules.map(rule => rule.id === savedRule.id ? savedRule : rule),
    );
  }

  private removeEmptySourceFilter(deletedRule: CharacterFeatureRule): void {
    if (this.sourceIdFilter() !== deletedRule.sourceId) {
      return;
    }

    const sourceStillExists = this.rules().some(rule =>
      rule.sourceType === deletedRule.sourceType &&
      rule.sourceId === deletedRule.sourceId,
    );

    if (!sourceStillExists) {
      this.sourceIdFilter.set(null);
    }
  }

  private handleSaveError(error: {
    error?: { error?: string; message?: string };
  }): void {
    this.error.set(
      error.error?.error ??
      error.error?.message ??
      'Impossible d’enregistrer cette attribution.',
    );
    this.submitting.set(false);
  }

  private sortByName<T extends { name: string }>(items: T[]): T[] {
    return [...items].sort((first, second) =>
      first.name.localeCompare(second.name, 'fr'),
    );
  }

  private normalizeSearch(value: string): string {
    return value
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLocaleLowerCase('fr')
      .trim();
  }

  private emptyForm(): RuleForm {
    return {
      featureDefinitionId: null,
      sourceType: 'class',
      sourceId: null,
      unlockLevel: 1,
      progressionThreshold: null,
      displayOrder: 0,
    };
  }

  private clearMessages(): void {
    this.error.set(null);
    this.success.set(null);
  }
}

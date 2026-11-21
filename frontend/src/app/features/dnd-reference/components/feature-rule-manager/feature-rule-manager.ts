import { ReferencePicker } from '../reference-picker/reference-picker';
import { REFERENCE_EDITOR_SCOPE, REFERENCE_VIEW_ORIGIN, REFERENCE_READ_ONLY, matchesReferenceOrigin } from '@core/services/reference-editor-context';
import { ReferenceFeatureEditorApiService } from '@core/services/reference-feature-editor-api.service';
import { ReferenceCatalogueEditorApiService } from '@core/services/reference-catalogue-editor-api.service';
import { Component, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { forkJoin } from 'rxjs';
import {
  CharacterFeatureApiService,
  CharacterFeatureDefinition,
  CharacterFeatureRule,
  CreateFeatureRulePayload,
  FeatureSourceType,
} from '@core/services/legacy-character-feature-api.service';
import {
  ClassReference,
  DndReferenceApiService,
  FeatReference,
  ProgressionReference,
  RaceReference,
  SubclassReference,
} from '@core/services/legacy-dnd-reference-api.service';

type AssignmentSource = FeatureSourceType | 'beast';
interface BeastRecord { id: number; name: string; slug: string }
interface BeastLink { beastId: number; featureId: number }
interface BeastApiResponse { beasts: BeastRecord[]; features: {id: number; name: string}[]; links: BeastLink[] }
interface BeastAssignment { id: number; sourceType: 'beast'; sourceId: number; sourceName: string; feature: CharacterFeatureDefinition; unlockLevel: number; displayOrder: number; progressionThreshold: null; editable: boolean }
type DisplayAssignment = CharacterFeatureRule | BeastAssignment;

interface SourceOption {
  custom?: boolean;
  id: number;
  name: string;
}

interface RuleForm {
  featureDefinitionId: number | null;
  sourceType: AssignmentSource;
  sourceId: number | null;
  unlockLevel: number;
  progressionThreshold: number | null;
  displayOrder: number;
}

@Component({
  selector: 'app-feature-rule-manager',
  standalone: true,
  imports: [FormsModule, ReferencePicker],
  providers: [ReferenceFeatureEditorApiService, ReferenceCatalogueEditorApiService],
  templateUrl: './feature-rule-manager.html',
  styleUrl: './feature-rule-manager.scss',
})
export class FeatureRuleManager {
  protected readonly contextReadOnly = inject(REFERENCE_READ_ONLY);
  protected readonly viewOrigin = inject(REFERENCE_VIEW_ORIGIN);
  private readonly beastScope = this.viewOrigin === 'OFFICIAL' ? 'official' : inject(REFERENCE_EDITOR_SCOPE);

  private readonly http = inject(HttpClient);
  private readonly featureApi = inject(ReferenceFeatureEditorApiService);
  private readonly referenceApi = inject(ReferenceCatalogueEditorApiService);

  protected readonly classes = signal<ClassReference[]>([]);
  protected readonly races = signal<RaceReference[]>([]);
  protected readonly feats = signal<FeatReference[]>([]);
  protected readonly subclasses = signal<SubclassReference[]>([]);
  protected readonly features = signal<CharacterFeatureDefinition[]>([]);
  protected readonly rules = signal<CharacterFeatureRule[]>([]);
  protected readonly progressions = signal<ProgressionReference[]>([]);
  protected readonly beasts = signal<BeastRecord[]>([]);
  protected readonly beastLinks = signal<BeastLink[]>([]);
  protected readonly beastAssignments = computed<BeastAssignment[]>(() => {
    const beasts = new Map(this.beasts().map(b => [b.id, b.name]));
    const features = new Map(this.features().map(f => [f.id, f]));
    return this.beastLinks().flatMap(link => {
      const feature = features.get(link.featureId);
      const name = beasts.get(link.beastId);
      return feature && name ? [{id: -((link.beastId * 1000000) + link.featureId), sourceType: 'beast' as const, sourceId: link.beastId, sourceName: name, feature, unlockLevel: 1, displayOrder: 0, progressionThreshold: null, editable: true}] : [];
    });
  });
  protected readonly allRules = computed<DisplayAssignment[]>(() => [...this.rules(), ...this.beastAssignments()]);

  protected readonly selectedRuleId = signal<number | null>(null);
  protected readonly ruleSearch = signal('');
  protected readonly sourceTypeFilter =
    signal<AssignmentSource | 'all'>('all');
  protected readonly sourceIdFilter = signal<number | null>(null);
  protected readonly loading = signal(true);
  protected readonly submitting = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);

  protected form: RuleForm = this.emptyForm();

  protected readonly selectedRule = computed(() => {
    const selectedId = this.selectedRuleId();
    return this.allRules().find(rule => rule.id === selectedId) ?? null;
  });

  protected readonly filteredRules = computed(() => {
    const search = this.normalizeSearch(this.ruleSearch());
    const sourceType = this.sourceTypeFilter();
    const sourceId = this.sourceIdFilter();

    return this.allRules().filter(item => item.sourceType === 'beast' || matchesReferenceOrigin(item as CharacterFeatureRule, this.viewOrigin))
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

    for (const rule of this.allRules()) {
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

  protected selectRule(rule: DisplayAssignment): void {
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
    this.clearMessages();
  }

  protected startCreation(): void {
    this.selectedRuleId.set(null);
    this.form = this.emptyForm();
    this.clearMessages();
  }

  protected readonly readOnly = computed(() => this.contextReadOnly || (this.selectedRuleId() !== null && this.selectedRule()?.editable !== true));
  protected save(): void {
    if (this.readOnly() || this.submitting()) return;
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
    if (this.form.sourceType === 'progression' && (this.form.progressionThreshold === null || !Number.isInteger(Number(this.form.progressionThreshold)) || Number(this.form.progressionThreshold) < 0)) {
      this.error.set('Saisissez un seuil entier positif ou nul.');
      return;
    }
    this.submitting.set(true);
    this.clearMessages();

    if (this.form.sourceType === 'beast') {
      const beastId = Number(this.form.sourceId);
      const featureId = Number(this.form.featureDefinitionId);
      const otherLinks = this.beastLinks().filter(link => link.beastId === beastId && (selectedId === null || link.featureId !== this.selectedRule()?.feature.id));
      const ids = [...new Set([...otherLinks.map(link => link.featureId), featureId])];
      this.saveBeastLinks(beastId, ids, selectedId === null ? 'La capacité a bien été attribuée.' : 'L’attribution a bien été mise à jour.', featureId);
      return;
    }

    if (selectedId !== null) {
      this.featureApi.updateRule(selectedId, {
        featureDefinitionId: Number(this.form.featureDefinitionId),
        sourceType: this.form.sourceType as FeatureSourceType,
        sourceId: Number(this.form.sourceId),
        ...(this.form.sourceType === 'progression'
          ? {
              progressionThreshold:
                Number(this.form.progressionThreshold),
              unlockLevel: null,
            }
          : {
              unlockLevel: Number(this.form.unlockLevel),
              progressionThreshold: null,
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
      sourceType: this.form.sourceType as FeatureSourceType,
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

  protected deleteRule(rule: DisplayAssignment): void {
    if (rule.editable !== true) return;
    const confirmed = window.confirm(
      `Retirer « ${rule.feature.name} » de « ${rule.sourceName} » ?`,
    );

    if (!confirmed || this.submitting()) {
      return;
    }

    this.submitting.set(true);
    this.clearMessages();

    if (rule.sourceType === 'beast') {
      const ids = this.beastLinks().filter(link => link.beastId === rule.sourceId && link.featureId !== rule.feature.id).map(link => link.featureId);
      this.saveBeastLinks(rule.sourceId, ids, 'L’attribution a bien été supprimée.');
      return;
    }

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
    sourceType: AssignmentSource | 'all',
  ): void {
    this.sourceTypeFilter.set(sourceType);
    this.sourceIdFilter.set(null);
  }

protected resetFilters(): void {
    this.ruleSearch.set('');
    this.sourceTypeFilter.set('all');
    this.sourceIdFilter.set(null);
  }

  protected sourceTypeLabel(sourceType: AssignmentSource): string {
    return {
      class: 'Classe',
      subclass: 'Sous-classe',
      race: 'Race',
      feat: 'Don',
      progression: 'Progression',
      beast: 'Bête',
    }[sourceType];
  }

  protected className(classId: number): string {
    return this.classes().find(characterClass =>
      characterClass.id === classId
    )?.name ?? 'Classe inconnue';
  }

  protected trackRule(_: number, rule: DisplayAssignment): number {
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
      beasts: this.http.get<BeastApiResponse>(`/api/dnd/beast-features/scope/${this.beastScope}`),
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
        this.beasts.set(result.beasts.beasts.map(b => ({...b, id: Number(b.id)})));
        this.beastLinks.set(result.beasts.links.map(l => ({beastId: Number(l.beastId), featureId: Number(l.featureId)})));
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

  private sourceOptions(sourceType: AssignmentSource): SourceOption[] {
    switch (sourceType) {
      case 'beast':
        return this.beasts().map(b => ({id: b.id, name: b.name}));
      case 'class':
        return this.classes().map(item => ({
          id: item.id, custom: item.custom,
          name: item.name,
        }));

      case 'subclass':
        return this.subclasses().map(item => ({
          id: item.id, custom: item.custom,
          name: `${this.className(item.classId)} — ${item.name}`,
        }));

      case 'race':
        return this.races().map(item => ({
          id: item.id, custom: item.custom,
          name: item.name,
        }));

      case 'feat':
        return this.feats().map(item => ({
          id: item.id, custom: item.custom,
          name: item.name,
        }));

      case 'progression':
        return this.progressions().map(item => ({
          id: item.id, custom: item.custom,
          name: item.name,
        }));
    }
  }

  private saveBeastLinks(beastId: number, featureIds: number[], message: string, selectedFeatureId?: number): void {
    this.http.put<{beastId: number; featureIds: number[]}>(`/api/dnd/beast-features/scope/${this.beastScope}/${beastId}`, {featureIds}).subscribe({
      next: response => {
        this.beastLinks.update(links => [
          ...links.filter(link => link.beastId !== beastId),
          ...response.featureIds.map(featureId => ({beastId, featureId: Number(featureId)})),
        ]);
        this.selectedRuleId.set(selectedFeatureId === undefined ? null : -((beastId * 1000000) + selectedFeatureId));
        if (selectedFeatureId === undefined) this.form = this.emptyForm();
        this.success.set(message);
        this.submitting.set(false);
      },
      error: error => this.handleSaveError(error),
    });
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

    const sourceStillExists = this.allRules().some(rule =>
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

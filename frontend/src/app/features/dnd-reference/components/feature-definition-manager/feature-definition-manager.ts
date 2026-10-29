import { ReferencePicker } from '../reference-picker/reference-picker';
import { REFERENCE_VIEW_ORIGIN, REFERENCE_READ_ONLY, matchesReferenceOrigin } from '@core/services/reference-editor-context';
import { ReferenceFeatureEditorApiService, FeatureSpellGrant, FeatureSpellGrantPayload } from '@core/services/reference-feature-editor-api.service';
import { ReferenceCatalogueEditorApiService } from '@core/services/reference-catalogue-editor-api.service';
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
} from '../../../../core/services/legacy-character-feature-api.service';

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
  imports: [FormsModule, ReferencePicker],
  providers: [ReferenceFeatureEditorApiService, ReferenceCatalogueEditorApiService],
  templateUrl: './feature-definition-manager.html',
  styleUrl: './feature-definition-manager.scss',
})
export class FeatureDefinitionManager {
  protected readonly contextReadOnly = inject(REFERENCE_READ_ONLY);
  protected readonly viewOrigin = inject(REFERENCE_VIEW_ORIGIN);

  private readonly api = inject(ReferenceFeatureEditorApiService);

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

  protected readonly grantClasses = signal<{ id: number; name: string }[]>([]);
  protected readonly grantSpells = signal<{ id: number; name: string; level: number }[]>([]);
  protected grantCastingAbility = '';
  protected readonly abilityLabels: Record<string,string> = { INT:'Intelligence', WIS:'Sagesse', CHA:'Charisme', STR:'Force', DEX:'Dextérité', CON:'Constitution', CHOICE:'Au choix du personnage' };
  protected readonly spellGrants = signal<FeatureSpellGrant[]>([]);
  protected readonly spellGrantLoading = signal(false);
  protected readonly spellGrantEditing = signal(false);
  protected readonly spellGrantError = signal<string | null>(null);
  protected readonly choiceAllowed = signal(false);
  protected grantMode: 'fixed' | 'choice' = 'fixed';
  protected grantSpellId: number | null = null;
  protected grantSpellLevel = 0;
  protected grantClassId: number | null = null;
  protected grantSchool = '';
  protected grantUnlockLevel = 1;
  protected editingGrantId: number | null = null;
  private editedGrant: FeatureSpellGrant | null = null;
  protected configureCasting = false;
  protected freeCasting = false;
  protected freeUses = 1;
  protected freeRecharge: 'short-rest' | 'long-rest' = 'long-rest';
  protected freeLevel: number | null = null;
  protected standardCasting = true;

  protected startSpellGrant(): void {
    this.editedGrant = null;
    this.grantMode = 'fixed'; this.grantSpellId = null; this.grantUnlockLevel = 1;
    this.grantSpellLevel = 0; this.grantClassId = null; this.grantSchool = '';
    this.editingGrantId = null; this.configureCasting = true;
    this.freeCasting = false; this.freeUses = 1; this.freeRecharge = 'long-rest';
    this.freeLevel = null; this.standardCasting = true; this.grantCastingAbility = '';
    this.spellGrantEditing.set(true);
  }

  protected editSpellGrant(grant: FeatureSpellGrant): void {
    this.editedGrant = grant;
    this.editingGrantId = grant.id; this.grantMode = grant.mode;
    this.grantSpellId = grant.spellId; this.grantSpellLevel = grant.spellLevel ?? 0;
    this.grantClassId = grant.classId; this.grantSchool = grant.schools?.[0] ?? '';
    this.grantUnlockLevel = grant.unlockLevel; this.grantCastingAbility = grant.castingAbility ?? '';
    const free = grant.castingRules?.methods.find(method => method.kind === 'resource');
    this.configureCasting = false; // Editing the ability alone preserves legacy methods/costs.
    this.freeCasting = !!free; this.freeUses = grant.freeUses ?? 1;
    this.freeRecharge = grant.rechargeType === 'short-rest' ? 'short-rest' : 'long-rest';
    this.freeLevel = free?.kind === 'resource' ? free.castingLevel : null;
    this.standardCasting = grant.castingRules?.methods.some(method => method.kind === 'standard-slot') ?? true;
    this.spellGrantEditing.set(true);
  }

  protected enableSpellGrant(grant: FeatureSpellGrant): void {
    const id = this.selectedFeatureId();
    if (id === null || this.readOnly() || this.spellGrantLoading()) return;
    this.spellGrantLoading.set(true);
    this.api.configureFeatureSpellGrant(id, grant.id, { enabled: true }).subscribe({
      next: response => { this.spellGrants.set(response.grants); this.spellGrantLoading.set(false); },
      error: error => { this.spellGrantError.set(error.error?.detail ?? 'Réactivation impossible.'); this.spellGrantLoading.set(false); },
    });
  }

  protected loadSpellGrants(featureId: number): void {
    this.choiceAllowed.set(false);
    this.spellGrantLoading.set(true);
    this.spellGrantError.set(null);
    this.api.getFeatureSpellGrantOptions(featureId).subscribe({
      next: options => { if (this.selectedFeatureId() === featureId) { this.choiceAllowed.set(options.choiceAllowed); this.grantClasses.set(options.classes); this.grantSpells.set(options.spells); } },
      error: () => this.spellGrantError.set('Impossible de charger les listes de sorts et de classes.'),
    });
    this.api.getFeatureSpellGrants(featureId).subscribe({
      next: response => { if (this.selectedFeatureId() === featureId) this.spellGrants.set(response.grants); this.spellGrantLoading.set(false); },
      error: () => { this.spellGrantError.set('Impossible de charger les sorts octroyés.'); this.spellGrantLoading.set(false); },
    });
  }

  protected addSpellGrant(): void {
    const featureId = this.selectedFeatureId();
    if (featureId === null || this.readOnly() || this.spellGrantLoading()) return;
    if (this.grantMode === 'choice' && !this.choiceAllowed()) {
      this.spellGrantError.set('Une progression ne peut accorder que des sorts fixes.'); return;
    }
    const payload: FeatureSpellGrantPayload = this.grantMode === 'fixed'
      ? { mode: 'fixed', spellId: Number(this.grantSpellId), unlockLevel: Number(this.grantUnlockLevel), castingAbility: this.grantCastingAbility || null }
      : { mode: 'choice', spellLevel: Number(this.grantSpellLevel), classId: this.grantClassId ? Number(this.grantClassId) : null, schools: this.grantSchool ? [this.grantSchool] : [], unlockLevel: Number(this.grantUnlockLevel), castingAbility: this.grantCastingAbility || null };
    if (!Number.isInteger(payload.unlockLevel) || payload.unlockLevel < 1 || payload.unlockLevel > 20 || (payload.mode === 'fixed' && (!payload.spellId || payload.spellId < 1))) {
      this.spellGrantError.set('Vérifie le sort et le niveau de déblocage.'); return;
    }
    if (payload.mode === 'choice' && this.editedGrant && this.grantSchool === (this.editedGrant.schools?.[0] ?? '')) {
      payload.schools = this.editedGrant.schools;
    }
    this.spellGrantLoading.set(true); this.spellGrantError.set(null);
    const casting = this.configureCasting ? {
      freeUses: this.freeCasting ? Number(this.freeUses) : 0, rechargeType: this.freeRecharge,
      castingLevel: this.freeLevel === null ? (this.grantMode === 'fixed' ? this.grantSpells().find(spell => spell.id === this.grantSpellId)?.level ?? 0 : this.grantSpellLevel) : Number(this.freeLevel),
      standardSlot: this.standardCasting,
    } : undefined;
    const request = this.editingGrantId === null
      ? this.api.addFeatureSpellGrant(featureId, { ...payload, ...(casting ? { casting } : {}) })
      : this.api.configureFeatureSpellGrant(featureId, this.editingGrantId, { ...Object.fromEntries(Object.entries(payload).filter(([key]) => key !== 'mode')), ...(casting ? { casting } : {}) });
    request.subscribe({
      next: response => { this.spellGrants.set(response.grants); this.spellGrantEditing.set(false); this.spellGrantLoading.set(false); },
      error: error => { this.spellGrantError.set(error.error?.detail ?? 'Impossible d’ajouter ce sort.'); this.spellGrantLoading.set(false); },
    });
  }

  protected removeSpellGrant(grantId: number): void {
    const featureId = this.selectedFeatureId();
    if (featureId === null || this.readOnly() || this.spellGrantLoading() || !window.confirm('Retirer ce sort de la capacité ?')) return;
    this.spellGrantLoading.set(true); this.spellGrantError.set(null);
    this.api.deleteFeatureSpellGrant(featureId, grantId).subscribe({
      next: response => { this.spellGrants.set(response.grants); this.spellGrantLoading.set(false); },
      error: () => { this.spellGrantError.set('Impossible de retirer ce sort.'); this.spellGrantLoading.set(false); },
    });
  }

  protected form: FeatureForm = this.emptyForm();

  protected readonly selectedFeature = computed(() => {
    const selectedId = this.selectedFeatureId();
    return this.features().find(feature => feature.id === selectedId) ?? null;
  });

  protected readonly filteredFeatures = computed(() => {
    const search = this.normalizeSearch(this.search());
    const activationFilter = this.activationFilter();

    return this.features().filter(item => matchesReferenceOrigin(item, this.viewOrigin)).filter(feature => {
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
    this.spellGrants.set([]); this.spellGrantEditing.set(false); this.loadSpellGrants(feature.id);
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

  protected deletePersonal(): void {
    const item = this.selectedFeature();
    if (!item || !item.custom || this.readOnly() || this.submitting() || !window.confirm('Supprimer cette création personnelle ?')) return;
    this.submitting.set(true);
    this.api.deletePersonalFeature(item.id).subscribe({
      next: () => { this.features.update(items => items.filter(existing => existing.id !== item.id)); this.startCreation(); this.submitting.set(false); },
      error: error => { this.error.set(error.error?.message ?? 'Suppression impossible : référence utilisée ou inaccessible.'); this.submitting.set(false); },
    });
  }
  protected startCreation(): void {
    this.selectedFeatureId.set(null);
    this.spellGrants.set([]); this.spellGrantEditing.set(false);
    this.form = this.emptyForm();
    this.clearMessages();
  }

  protected readonly readOnly = computed(() => this.contextReadOnly || this.selectedFeatureId() !== null && this.selectedFeature()?.editable !== true);
  protected save(): void {
    if (this.readOnly() || this.submitting()) return;
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
      custom: this.api.scope !== 'official',
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

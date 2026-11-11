import { FeatureProficiencyEditorApiService, ProficiencyDefinition, FeatureProficiencyRule } from '@core/services/feature-proficiency-editor-api.service';
import { CampaignApiService, CampaignApiResponse } from '@core/services/campaign-api.service';
import { CharacterApiService, CharacterApiResponse } from '@core/services/character-api.service';
import { CharacterFeatureAssignmentApiService, FeatureAssignment } from '@core/services/character-feature-assignment-api.service';
import { ReferencePicker } from '../reference-picker/reference-picker';
import { REFERENCE_VIEW_ORIGIN, REFERENCE_READ_ONLY, matchesReferenceOrigin } from '@core/services/reference-editor-context';
import { ReferenceFeatureEditorApiService, FeatureSpellGrant, FeatureSpellGrantPayload } from '@core/services/reference-feature-editor-api.service';
import { ReferenceCatalogueEditorApiService } from '@core/services/reference-catalogue-editor-api.service';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { forkJoin } from 'rxjs';
import { FeatureChoiceEditorApiService, CustomChoiceRow } from '@core/services/feature-choice-editor-api.service';
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
  private readonly choicesApi = inject(FeatureChoiceEditorApiService);
  private readonly proficiencyApi = inject(FeatureProficiencyEditorApiService);
  protected readonly proficiencyDefinitions = signal<ProficiencyDefinition[]>([]);
  protected readonly proficiencyRules = signal<FeatureProficiencyRule[]>([]);
  protected readonly proficiencyBusy = signal(false);
  protected readonly proficiencyMessage = signal('');
  protected proficiencyFeatureId: number | null = null;
  protected proficiencyRuleId: number | null = null;
  protected proficiencyLabel = '';
  protected proficiencyCount = 1;
  protected proficiencyOptionIds: number[] = [];
  protected readonly proficiencyType = signal('skill');
  protected readonly proficiencyTypes = computed(() => [...new Set(this.proficiencyDefinitions().map(d => d.type))]);
  protected readonly filteredProficiencies = computed(() => this.proficiencyDefinitions().filter(d => d.type === this.proficiencyType()));

  protected editProficiencies(featureId: number): void {
    this.proficiencyFeatureId = featureId;
    this.proficiencyRuleId = null;
    this.proficiencyLabel = '';
    this.proficiencyCount = 1;
    this.proficiencyOptionIds = [];
    this.proficiencyMessage.set('');
    this.proficiencyBusy.set(true);
    forkJoin({definitions: this.proficiencyApi.definitions(), rules: this.proficiencyApi.rules(featureId)}).subscribe({
      next: ({definitions, rules}) => {
        if (this.proficiencyFeatureId !== featureId) return;
        this.proficiencyDefinitions.set(definitions);
        this.proficiencyRules.set(rules);
        this.proficiencyType.set(definitions.find(d => d.type === 'skill')?.type ?? definitions[0]?.type ?? 'skill');
        this.proficiencyBusy.set(false);
      },
      error: e => { this.proficiencyBusy.set(false); this.proficiencyMessage.set(e.error?.detail ?? 'Impossible de charger les maîtrises.'); },
    });
  }

  protected loadProficiencyRule(id: number | null): void {
    const rule = this.proficiencyRules().find(r => r.id === id);
    this.proficiencyRuleId = rule?.id ?? null;
    this.proficiencyLabel = rule?.label ?? '';
    this.proficiencyCount = rule?.choiceCount ?? 1;
    this.proficiencyOptionIds = rule?.options.map(o => Number(o.id)) ?? [];
    this.proficiencyMessage.set('');
  }

  protected setProficiencyType(type: string): void { this.proficiencyType.set(type); }

  protected toggleProficiency(id: number, checked: boolean): void {
    this.proficiencyOptionIds = checked
      ? [...new Set([...this.proficiencyOptionIds, id])]
      : this.proficiencyOptionIds.filter(value => value !== id);
  }

  protected saveProficiency(): void {
    const featureId = this.proficiencyFeatureId;
    if (featureId === null || !this.proficiencyLabel.trim() || !this.proficiencyOptionIds.length || this.proficiencyCount > this.proficiencyOptionIds.length || this.proficiencyBusy()) return;
    this.proficiencyBusy.set(true);
    this.proficiencyApi.save(featureId, {
      ...(this.proficiencyRuleId === null ? {} : {id: this.proficiencyRuleId}),
      label: this.proficiencyLabel.trim(), choiceCount: Number(this.proficiencyCount), optionIds: this.proficiencyOptionIds,
    }).subscribe({
      next: () => {
        this.proficiencyBusy.set(false);
        this.editProficiencies(featureId);
        this.proficiencyMessage.set('Règle de maîtrise enregistrée.');
      },
      error: e => { this.proficiencyBusy.set(false); this.proficiencyMessage.set(e.error?.detail ?? e.error?.message ?? 'Enregistrement impossible.'); },
    });
  }

  protected readonly activeEditorTab = signal<'general' | 'choices'>('general');
  protected readonly choiceRows = signal<CustomChoiceRow[]>([]);
  protected readonly choiceRules = signal<{ id: number; sourceName: string; unlockLevel: number }[]>([]);
  protected readonly choiceBusy = signal(false);
  protected readonly choiceError = signal<string | null>(null);
  protected readonly choiceSuccess = signal<string | null>(null);
  protected choiceRuleId: number | null = null;
  protected choiceName = '';
  protected choiceCount = 1;
  protected choiceUniqueness: 'group' | 'character' | 'none' = 'group';
  protected choiceReplacement: 'none' | 'class-level' | 'grant-level' = 'none';
  protected choiceOptionIds: number[] = [];
  protected choiceAddId: number | null = null;
  protected newChoiceName = '';
  protected newChoiceDescription = '';
  protected newChoiceActivation: FeatureActivationType = 'passive';
  protected readonly creatingChoiceOption = signal(false);

  protected createChoiceOption(): void {
    const name = this.newChoiceName.trim();
    if (!name || this.selectedFeatureId() === null || this.contextReadOnly || this.creatingChoiceOption()) return;
    const slug = `choice-${this.selectedFeatureId()}-${name.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')}-${Date.now().toString(36)}`;
    this.creatingChoiceOption.set(true);
    this.choiceError.set(null);
    this.api.createFeature({
      slug, name, description: this.newChoiceDescription.trim() || null,
      activationType: this.newChoiceActivation, resourceDefinitionId: null,
      visible: true, custom: true,
    }).subscribe({
      next: ({ feature }) => {
        this.replaceFeature(feature);
        this.choiceOptionIds = [...this.choiceOptionIds, feature.id];
        this.newChoiceName = '';
        this.newChoiceDescription = '';
        this.newChoiceActivation = 'passive';
        this.creatingChoiceOption.set(false);
        this.choiceSuccess.set('Option créée et ajoutée à la sélection. Enregistre les choix pour confirmer son rattachement.');
      },
      error: error => {
        this.creatingChoiceOption.set(false);
        this.choiceError.set(error.error?.detail ?? error.error?.message ?? 'Création de la sous-capacité impossible.');
      },
    });
  }


  protected openChoices(): void {
    const id = this.selectedFeatureId();
    if (id === null || this.contextReadOnly) return;
    this.activeEditorTab.set('choices');
    this.choiceBusy.set(true);
    this.choiceError.set(null);
    forkJoin({ choices: this.choicesApi.get(id), rules: this.api.getRules() }).subscribe({
      next: ({ choices, rules }) => {
        if (this.selectedFeatureId() !== id) return;
        this.choiceRows.set(choices.choices);
        this.choiceRules.set(rules.rules.filter(r => r.feature.id === id && (r.origin === 'CUSTOM' || r.origin === 'OFFICIAL') && (r.sourceType === 'class' || r.sourceType === 'subclass' || r.sourceType === 'race')).map(r => ({ id: r.id, sourceName: r.sourceName, unlockLevel: r.unlockLevel })));
        this.choiceBusy.set(false);
        this.loadChoiceRow(this.choiceRows()[0] ?? null);
      },
      error: e => { this.choiceBusy.set(false); this.choiceError.set(e.error?.detail ?? 'Impossible de charger les choix.'); },
    });
  }

  protected choiceRowForRule(ruleId: number): CustomChoiceRow | null { return this.choiceRows().find(row => row.rule_id === ruleId) ?? null; }

  protected changeChoiceRule(ruleId: number): void { this.loadChoiceRow(this.choiceRowForRule(ruleId)); this.choiceRuleId = ruleId; }

  protected loadChoiceRow(row: CustomChoiceRow | null): void {
    this.choiceRuleId = row?.rule_id ?? this.choiceRules()[0]?.id ?? null;
    this.choiceName = row?.name ?? this.selectedFeature()?.name ?? '';
    this.choiceCount = row?.choice_count ?? 1;
    this.choiceUniqueness = row?.uniqueness ?? 'group';
    this.choiceReplacement = row?.replacement ?? 'none';
    this.choiceOptionIds = row?.options.map(o => o.featureId) ?? [];
    this.choiceAddId = null;
  }

  protected choiceOptionName(id: number): string { return this.features().find(f => f.id === id)?.name ?? `Capacité #${id}`; }

  protected removeChoiceOption(id: number): void { this.choiceOptionIds = this.choiceOptionIds.filter(value => value !== id); }

  protected addChoiceOption(): void {
    if (this.choiceAddId === null || this.choiceAddId === this.selectedFeatureId() || this.choiceOptionIds.includes(this.choiceAddId)) return;
    this.choiceOptionIds = [...this.choiceOptionIds, this.choiceAddId];
    this.choiceAddId = null;
  }

  protected saveChoices(): void {
    const id = this.selectedFeatureId();
    if (id === null || this.choiceRuleId === null || this.choiceBusy() || this.contextReadOnly) return;
    this.choiceBusy.set(true); this.choiceError.set(null); this.choiceSuccess.set(null);
    this.choicesApi.save(id, {
      ruleId: this.choiceRuleId, name: this.choiceName, choiceCount: Number(this.choiceCount),
      uniqueness: this.choiceUniqueness, replacement: this.choiceReplacement, options: this.choiceOptionIds,
    }).subscribe({
      next: () => { this.choiceBusy.set(false); this.choiceSuccess.set('Choix enregistrés.'); this.openChoices(); },
      error: e => { this.choiceBusy.set(false); this.choiceError.set(e.error?.detail ?? e.error?.message ?? 'Enregistrement impossible.'); },
    });
  }

  private readonly campaignApi = inject(CampaignApiService);
  private readonly characterApi = inject(CharacterApiService);
  private readonly assignmentApi = inject(CharacterFeatureAssignmentApiService);
  protected readonly assignmentCampaigns = signal<CampaignApiResponse[]>([]);
  protected readonly assignmentCharacters = signal<CharacterApiResponse[]>([]);
  protected readonly featureAssignments = signal<FeatureAssignment[]>([]);
  protected readonly assignmentBusy = signal(false);
  protected readonly assignmentMessage = signal<string | null>(null);
  protected assignmentCampaignId: number | null = null;
  protected assignmentCharacterId: number | null = null;

  protected loadAssignmentCampaigns(): void {
    this.campaignApi.list().subscribe({
      next: campaigns => {
        this.assignmentCampaigns.set(campaigns);
        if (campaigns.length && this.assignmentCampaignId === null) {
          this.assignmentCampaignId = campaigns[0].id;
          this.loadAssignmentCharacters();
        }
      },
      error: () => this.assignmentMessage.set('Impossible de charger les campagnes.'),
    });
  }

  protected loadAssignmentCharacters(): void {
    this.assignmentCharacterId = null;
    this.assignmentCharacters.set([]);
    if (this.assignmentCampaignId === null) return;
    this.characterApi.list(this.assignmentCampaignId).subscribe({
      next: characters => this.assignmentCharacters.set(characters),
      error: () => this.assignmentMessage.set('Impossible de charger les personnages.'),
    });
  }

  protected loadFeatureAssignments(featureId: number): void {
    this.featureAssignments.set([]);
    this.assignmentMessage.set(null);
    this.assignmentApi.list(featureId).subscribe({
      next: response => { if (this.selectedFeatureId() === featureId) this.featureAssignments.set(response.assignments); },
      error: () => this.assignmentMessage.set('Impossible de charger les attributions.'),
    });
  }

  protected assignFeatureToCharacter(): void {
    const featureId = this.selectedFeatureId();
    const characterId = this.assignmentCharacterId;
    if (featureId === null || characterId === null || this.assignmentBusy()) return;
    this.assignmentBusy.set(true);
    this.assignmentApi.assign(featureId, characterId).subscribe({
      next: () => { this.assignmentBusy.set(false); this.assignmentMessage.set('Capacité attribuée.'); this.loadFeatureAssignments(featureId); },
      error: error => { this.assignmentBusy.set(false); this.assignmentMessage.set(error.error?.message ?? 'Attribution impossible.'); },
    });
  }

  protected removeFeatureFromCharacter(characterId: number): void {
    const featureId = this.selectedFeatureId();
    if (featureId === null || this.assignmentBusy()) return;
    this.assignmentBusy.set(true);
    this.assignmentApi.remove(featureId, characterId).subscribe({
      next: () => { this.assignmentBusy.set(false); this.assignmentMessage.set('Attribution retirée.'); this.loadFeatureAssignments(featureId); },
      error: error => { this.assignmentBusy.set(false); this.assignmentMessage.set(error.error?.message ?? 'Retrait impossible.'); },
    });
  }


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
    this.configureCasting = Array.isArray(grant.castingRules?.methods) && grant.castingRules.methods.length > 0;
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
    this.loadAssignmentCampaigns();
  }

  protected selectFeature(feature: CharacterFeatureDefinition): void {
    this.activeEditorTab.set('general');
    this.proficiencyFeatureId = null;
    this.selectedFeatureId.set(feature.id);
    this.editProficiencies(feature.id);
    this.loadFeatureAssignments(feature.id);
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
    this.activeEditorTab.set('general');
    this.selectedFeatureId.set(null);
    this.featureAssignments.set([]);
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

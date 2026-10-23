import { Component, input, OnInit, output, signal, computed } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Observable } from 'rxjs';
import {
  AbilityAdvancementPayload,
  CharacterProfile,
  FeatAdvancementPayload,
  HitPointGainMethod,
  LevelUpClassOption,
  LevelUpOptions,
  LevelUpPayload,
  LevelUpSpellPreview,
  LevelUpSpellOpportunity,
  LevelUpSpellChoice,
  SpellQualificationResponse,
  TrainingOptions,
  TrainingChoice,
  TrainingChoicesPayload,
} from '@core/services/character-api.service';
import {
  AbilityKey,
  AbilityReference,
  FeatReference,
} from '@core/services/dnd-reference-api.service';


type AdvancementMode = 'ability' | 'feat';
type AbilityIncreaseMode = 'single' | 'double';
type LevelUpHitPointMethod = Exclude<HitPointGainMethod, 'first_level'>;

interface LevelUpForm {
  classId: number | null;
  subclassId: number | null;
  advancementMode: AdvancementMode;
  abilityIncreaseMode: AbilityIncreaseMode;
  firstAbility: AbilityKey | null;
  secondAbility: AbilityKey | null;
  featId: number | null;
  featAbility: AbilityKey | null;
  hitPointMethod: LevelUpHitPointMethod;
  hitPointGain: number | null;
}

@Component({
  selector: 'app-character-level-up',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './character-level-up.html',
  styleUrls: ['./character-level-up.scss', './spell-cards.scss'],
})
export class CharacterLevelUp implements OnInit {
  readonly character = input.required<CharacterProfile>();
  readonly options = input.required<LevelUpOptions>();
  readonly abilities = input.required<AbilityReference[]>();
  readonly feats = input.required<FeatReference[]>();

  readonly submitted = output<LevelUpPayload>();
  readonly cancelled = output<void>();

  readonly submitting = input(false);
  // Only the authenticated GM page supplies this action; the player form has none.
  readonly qualifySpell = input<((policyId: number, acquisitionId: number, ruleId: number, reason: string) => Observable<SpellQualificationResponse>) | null>(null);
  protected readonly qualificationOpen = signal(false);
  protected readonly qualificationReason = signal('');
  protected readonly qualificationLoading = signal(false);
  protected readonly qualificationError = signal<string | null>(null);
  protected readonly qualificationSuccess = signal<string | null>(null);

  protected confirmQualification(): void {
    const action = this.qualifySpell();
    const candidate = this.forgottenCandidate();
    const rule = this.activeReplacementRule();
    const reason = this.qualificationReason().trim();
    if (!action || !candidate?.requiresQualification || !rule || rule.ruleId === null || !reason
      || this.qualificationLoading() || this.submitting() || this.spellsLoading()) return;
    const version = this.spellVersion;
    this.qualificationLoading.set(true);
    this.qualificationError.set(null);
    this.qualificationSuccess.set(null);
    action(rule.policyId, candidate.acquisitionId, rule.ruleId, reason).subscribe({
      next: () => {
        if (version !== this.spellVersion) return;
        this.qualificationLoading.set(false);
        this.qualificationOpen.set(false);
        this.qualificationReason.set('');
        this.qualificationSuccess.set('Qualification enregistrée.');
        this.reloadSpells(true);
      },
      error: error => {
        if (version !== this.spellVersion) return;
        this.qualificationLoading.set(false);
        this.qualificationError.set(error?.error?.message ?? 'Impossible de qualifier ce sort.');
      },
    });
  }

  protected retrySpellPreview(): void { this.reloadSpells(true); }
  readonly trainingPreview = input<((classId: number, subclassId: number | null) => Observable<TrainingOptions>) | null>(null);
  readonly spellPreview = input<((classId: number, subclassId: number | null) => Observable<LevelUpSpellPreview>) | null>(null);
  protected readonly spells = signal<LevelUpSpellPreview | null>(null);
  protected readonly spellsLoading = signal(false);
  protected readonly spellsError = signal<string | null>(null);
  protected readonly spellSelection = signal<Record<string, number[]>>({});
  protected readonly replacementEnabled = signal(false);
  protected readonly forgottenAcquisitionId = signal<number | null>(null);
  protected readonly replacementSpellId = signal<number | null>(null);
  protected readonly replacementRuleKey = signal<string | null>(null);
  protected readonly replacementSearch = signal('');
  protected readonly replacementCandidates = computed(() => {
    const unique = new Map<number, { acquisitionId: number; spellId: number; requiresQualification: boolean; rules: LevelUpSpellOpportunity[] }>();
    for (const choice of this.spellOpportunities()) {
      if (!choice.replacement.maximum) continue;
      for (const acquisition of choice.replacement.acquisitions) {
        const entry = unique.get(acquisition.acquisitionId);
        if (entry) {
          entry.rules.push(choice);
          entry.requiresQualification ||= acquisition.requiresQualification;
        } else {
          unique.set(acquisition.acquisitionId, { ...acquisition, rules: [choice] });
        }
      }
    }
    return [...unique.values()];
  });
  protected readonly replacementBudgets = computed(() => {
    const budgets = new Map<string, number>();
    for (const choice of this.spellOpportunities()) {
      if (choice.replacement.maximum > 0) {
        const key = choice.replacement.budgetKey || this.spellKey(choice);
        // Shared budget: one limit, never sum the per-rule maxima.
        budgets.set(key, Math.max(budgets.get(key) ?? 0, choice.replacement.maximum));
      }
    }
    return [...budgets.values()].reduce((sum, maximum) => sum + maximum, 0);
  });
  protected readonly forgottenCandidate = computed(() =>
    this.replacementCandidates().find(candidate => candidate.acquisitionId === this.forgottenAcquisitionId()) ?? null
  );
  protected readonly replacementRules = computed(() => this.forgottenCandidate()?.rules ?? []);
  protected readonly activeReplacementRule = computed(() =>
    this.replacementRules().find(choice => this.spellKey(choice) === this.replacementRuleKey()) ?? null
  );
  protected readonly replacementOptions = computed(() => {
    const rule = this.activeReplacementRule();
    if (!rule) return [];
    const query = this.replacementSearch().trim().toLocaleLowerCase('fr');
    const known = new Set(this.spellPolicy()?.acquisitions.filter(acquisition => !acquisition.retirement_id).map(acquisition => acquisition.spell_id) ?? []);
    const learning = new Set(Object.values(this.spellSelection()).flat());
    return rule.replacementAvailable.filter(spell => !known.has(spell.id) && !learning.has(spell.id)
      && (!query || `${spell.name} ${spell.school} ${spell.level}`.toLocaleLowerCase('fr').includes(query)));
  });
protected spellLabel(spellId: number): string {
  const policy = this.spellPolicy();

  const acquisition = policy?.acquisitions.find(
    item => item.spell_id === spellId
  );

  if (acquisition && 'spell_name' in acquisition && typeof acquisition.spell_name === 'string') {
    return acquisition.spell_name;
  }

  // Chercher le nom parmi les sorts pouvant être oubliés.
  const replacement = policy?.choices
    .flatMap(choice => choice.replacement.acquisitions)
    .find(candidate => candidate.spellId === spellId);

  if (replacement?.spellName) {
    return replacement.spellName;
  }

  // Chercher parmi les sorts disponibles à l'apprentissage.
  const candidates = policy?.choices.flatMap(
    choice => [...choice.available, ...choice.replacementAvailable]
  ) ?? [];

  return candidates.find(spell => spell.id === spellId)?.name ?? `Sort n°${spellId}`;
}
  protected toggleReplacement(enabled: boolean): void {
    this.replacementEnabled.set(enabled);
    this.selectForgotten(null);
  }
  protected selectForgotten(acquisitionId: number | null): void {
    this.qualificationOpen.set(false);
    this.qualificationReason.set('');
    this.qualificationError.set(null);
    this.forgottenAcquisitionId.set(acquisitionId);
    this.replacementSpellId.set(null);
    this.replacementSearch.set('');
    const candidate = this.replacementCandidates().find(item => item.acquisitionId === acquisitionId);
    this.replacementRuleKey.set(candidate?.rules.length === 1 ? this.spellKey(candidate.rules[0]) : null);
  }
  protected selectReplacementRule(key: string): void {
    this.replacementRuleKey.set(key);
    this.replacementSpellId.set(null);
  }
  protected selectReplacementSpell(spellId: number): void {
    this.replacementSpellId.set(this.replacementSpellId() === spellId ? null : spellId);
  }
  protected replacementName(): string {
    const spell = this.activeReplacementRule()?.replacementAvailable.find(item => item.id === this.replacementSpellId());
    return spell?.name ?? 'Aucun sort sélectionné';
  }
  protected readonly spellLevelFilter = signal<number | null>(null);
  protected readonly spellSchoolFilter = signal<string | null>(null);
  protected readonly spellLevels = computed(() =>
    [...new Set(this.spellOpportunities().flatMap(choice => choice.available.map(spell => spell.level)))].sort((a, b) => a - b)
  );
  protected readonly spellSchools = computed(() =>
    [...new Set(this.spellOpportunities().flatMap(choice => choice.available.map(spell => spell.school)))].sort()
  );
  protected selectedSpells(opportunity: LevelUpSpellOpportunity): number[] {
    return this.spellSelection()[this.spellKey(opportunity)] ?? [];
  }
  protected toggleSpell(opportunity: LevelUpSpellOpportunity, spellId: number): void {
    if (opportunity.remaining <= 0) return;
    const key = this.spellKey(opportunity);
    const selected = this.selectedSpells(opportunity);
    const next = selected.includes(spellId)
      ? selected.filter(id => id !== spellId)
      : selected.length < opportunity.remaining ? [...selected, spellId] : [...selected.slice(1), spellId];
    // Prevent selecting one spell in multiple rules for the same progression.
    if (!selected.includes(spellId) && Object.entries(this.spellSelection()).some(([otherKey, ids]) => otherKey !== key && ids.includes(spellId))) return;
    this.spellSelection.update(current => ({ ...current, [key]: next }));
    // A spell learned during this level-up cannot also be the replacement.
    if (this.replacementSpellId() === spellId && !selected.includes(spellId)) this.replacementSpellId.set(null);
  }
  protected spellImage(spell: { id: number; name: string }): string | null {
    const candidate = spell as unknown as Record<string, unknown>;
    // Use only image fields actually returned by the catalogue API.
    for (const key of ['iconUrl', 'imageUrl', 'icon_url', 'image_url']) {
      const value = candidate[key];
      if (typeof value === 'string' && (value.startsWith('/') || value.startsWith('https://') || value.startsWith('http://'))) return value;
    }
    return null;
  }
  protected spellSymbol(school: string): string {
    const symbols: Record<string, string> = {
      Abjuration: '◇', Évocation: '✦', Nécromancie: '☠', Illusion: '◈',
      Divination: '◉', Enchantement: '✧', Invocation: '✺', Transmutation: '⬡',
    };
    return symbols[school] ?? '✧';
  }
  protected readonly spellSearch = signal('');
  private spellVersion = 0;
  protected readonly spellPolicy = computed(() => {
    const preview = this.spells();
    return preview?.policies.length === 1 ? preview.policies[0] : null;
  });
  protected readonly spellOpportunities = computed(() => this.spellPolicy()?.choices ?? []);
  protected readonly spellChoicesComplete = computed(() => {
    if (this.qualificationLoading()) return false;
    if (!this.spellPreview()) return true;
    const preview = this.spells();
    const policy = this.spellPolicy();
    if (!this.spellsLoading() && !this.spellsError() && preview?.status === 'no-spellcasting') {
      return preview.policies.length === 0 && Object.values(this.spellSelection()).every(ids => ids.length === 0) && !this.replacementEnabled();
    }
    if (this.spellsLoading() || this.spellsError() || !preview || !policy
      || preview.status === 'quota-configuration-required' || preview.status === 'quota-exceeded'
      || policy.blocked.length) return false;
    const seen = new Set<number>();
    const totals = new Map<string, number>();
    const keys = new Set(this.spellOpportunities().map(choice => this.spellKey(choice)));
    if (Object.entries(this.spellSelection()).some(([key, ids]) => ids.length && !keys.has(key))) return false;
    for (const choice of this.spellOpportunities()) {
      const selected = this.selectedSpells(choice);
      if (selected.length > choice.remaining) return false;
      for (const id of selected) {
        if (seen.has(id) || !choice.available.some(spell => spell.id === id)) return false;
        seen.add(id);
      }
      totals.set(choice.quota, (totals.get(choice.quota) ?? 0) + selected.length);
    }
    for (const choice of this.spellOpportunities()) {
      const total = totals.get(choice.quota) ?? 0;
      // Cumulative learning quotas cap opportunities; replacements never fill them.
      const capacity = choice.quota === 'known' || choice.quota === 'cantrips' || (choice.ruleId === null && choice.quota === 'spellbook')
        ? policy.remaining[choice.quota] : null;
      if (capacity !== null && total > capacity) return false;
      if (this.selectedSpells(choice).length < choice.remaining && (capacity === null || total < capacity)) return false;
    }
    if (!this.replacementEnabled()) return true;
    const candidate = this.forgottenCandidate();
    const rule = this.activeReplacementRule();
    const id = this.replacementSpellId();
    return !!candidate && !candidate.requiresQualification && !!rule && rule.replacement.maximum > 0
      && id !== null && !seen.has(id)
      && !policy.acquisitions.some(acquisition => acquisition.spell_id === id)
      && rule.replacementAvailable.some(spell => spell.id === id);
  });

  protected readonly selectedSpellChoices = computed<LevelUpSpellChoice[]>(() => {
    const choices: LevelUpSpellChoice[] = this.spellOpportunities().flatMap(opportunity =>
      this.selectedSpells(opportunity).map(spellId => ({ policyId: opportunity.policyId, spellId, ruleId: opportunity.ruleId })));
    const rule = this.activeReplacementRule();
    const acquisitionId = this.forgottenAcquisitionId();
    const spellId = this.replacementSpellId();
    if (this.replacementEnabled() && rule && acquisitionId !== null && spellId !== null) {
      choices.push({ policyId: rule.policyId, spellId, ruleId: rule.ruleId,
        replacesAcquisitionId: acquisitionId, replacementConfirmed: true });
    }
    return choices;
  });
  protected spellKey(opportunity: LevelUpSpellOpportunity): string {
    return `${opportunity.policyId}:${opportunity.key}:${opportunity.ruleId ?? 'simple'}`;
  }
  protected filteredSpells(opportunity: LevelUpSpellOpportunity) {
    const query = this.spellSearch().trim().toLocaleLowerCase('fr');
    return opportunity.available.filter(spell =>
      (this.spellLevelFilter() === null || spell.level === this.spellLevelFilter()) &&
      (this.spellSchoolFilter() === null || spell.school === this.spellSchoolFilter()) &&
      (!query || `${spell.name} ${spell.school} ${spell.level}`.toLocaleLowerCase('fr').includes(query))
    );
  }
  protected readonly training = signal<TrainingOptions | null>(null);
  protected readonly trainingLoading = signal(false);
  protected readonly trainingError = signal<string | null>(null);
  protected readonly trainingSelection = signal<Record<string, Array<number | string>>>({});
  private previewVersion = 0;
  protected readonly trainingChoices = computed(() =>
    (this.training()?.choices ?? []).filter(choice => this.isNewChoice(choice)),
  );
  protected readonly trainingComplete = computed(() => this.trainingChoices().every(choice => {
    const selected = this.trainingSelection()[choice.key] ?? [];
    return selected.length === choice.choiceCount && new Set(selected).size === selected.length
      && selected.every(id => this.choiceOptions(choice).some(option => option.value === id));
  }));
  protected readonly error = signal<string | null>(null);
  protected form: LevelUpForm = this.emptyForm();

  public ngOnInit(): void {
    const defaultClass =
      this.options().classes.find(option => option.currentLevel > 0)
      ?? this.options().classes[0]
      ?? null;

    this.form.classId = defaultClass?.id ?? null;
    this.applySelectedClassDefaults();
    this.reloadTraining();
    this.reloadSpells();
  }

  protected selectedClassOption(): LevelUpClassOption | null {
    return this.options().classes.find(
      option => option.id === this.form.classId,
    ) ?? null;
  }

  protected classChanged(): void {
    this.form.subclassId = null;
    this.form.advancementMode = 'ability';
    this.form.abilityIncreaseMode = 'single';
    this.form.firstAbility = null;
    this.form.secondAbility = null;
    this.form.featId = null;
    this.form.featAbility = null;
    this.form.hitPointMethod = 'average';
    this.form.hitPointGain = null;
    this.applySelectedClassDefaults();
    this.reloadTraining();
    this.reloadSpells();
  }

  protected subclassChanged(): void {
    this.reloadTraining();
    this.reloadSpells();
  }

  protected advancementModeChanged(mode: AdvancementMode): void {
    this.form.advancementMode = mode;
    this.form.firstAbility = null;
    this.form.secondAbility = null;
    this.form.featId = null;
    this.form.featAbility = null;
  }

  protected abilityIncreaseModeChanged(): void {
    this.form.firstAbility = null;
    this.form.secondAbility = null;
  }

  protected featChanged(): void {
    this.form.featAbility = null;
  }

  protected hitPointMethodChanged(): void {
    this.form.hitPointGain = this.form.hitPointMethod === 'average'
      ? this.selectedClassOption()?.averageHitPointGain ?? null
      : null;
  }

  protected selectedFeat(): FeatReference | null {
    return this.feats().find(feat => feat.id === this.form.featId) ?? null;
  }

  protected featAbilities(): AbilityReference[] {
    const feat = this.selectedFeat();

    if (!feat?.requiresAbilityChoice) {
      return [];
    }

    return feat.allowedAbilities.length === 0
      ? this.abilities()
      : this.abilities().filter(ability =>
          feat.allowedAbilities.includes(ability.value),
        );
  }

  /** Only rules unlocked by the selected class level may be edited on level-up. */
  protected isNewChoice(choice: TrainingChoice): boolean {
    const training = this.training();
    const selectedClass = this.selectedClassOption();
    if (!training || !selectedClass) return false;

    const rule = choice.kind === 'proficiency'
      ? training.proficiencyRules.find(item => item.ruleId === choice.ruleId)
      : choice.kind === 'expertise'
        ? this.findExpertiseRule(choice)
        : null;

    if (!rule) return false;
    if (rule.unlockLevel !== selectedClass.nextLevel) return false;
    if (rule.sourceType === 'class') return rule.sourceId === selectedClass.id;
    if (rule.sourceType === 'subclass') {
      return rule.sourceId === this.form.subclassId;
    }
    return false;
  }

  private findExpertiseRule(choice: TrainingChoice): {
    ruleId: number;
    sourceType: string;
    sourceId: number;
    unlockLevel: number;
    optionIds?: number[];
  } | null {
    // The API exposes expertiseRules separately from proficiencyRules.
    // Do not guess the unlock level from an existing selection.
    for (const entry of this.training()?.expertiseRules ?? []) {
      if (typeof entry !== 'object' || entry === null) continue;
      const rule = entry as Record<string, unknown>;
      if (rule['ruleId'] !== choice.ruleId) continue;
      if (typeof rule['sourceType'] !== 'string'
        || typeof rule['sourceId'] !== 'number'
        || typeof rule['unlockLevel'] !== 'number') continue;
      return {
        ruleId: choice.ruleId ?? -1,
        sourceType: rule['sourceType'],
        sourceId: rule['sourceId'],
        unlockLevel: rule['unlockLevel'],
        optionIds: Array.isArray(rule['optionIds'])
          ? rule['optionIds'].filter((id): id is number => typeof id === 'number')
          : undefined,
      };
    }
    return null;
  }

  protected choiceOptions(choice: TrainingChoice): Array<{ value: number | string; label: string }> {
    const training = this.training();
    if (!training) return [];
    if (choice.kind === 'language') {
      return training.definitions.languages.map(lang => ({ value: lang.slug, label: lang.name }));
    }

    const options = training.definitions.proficiencies;
    if (choice.kind === 'expertise') {
      const rule = this.findExpertiseRule(choice);
      const masteredIds = new Set(training.resolved.proficiencies.map(item => item.id));
      const existingExpertiseIds = new Set(training.resolved.expertises.map(item => item.proficiencyId));
      const selectedIds = new Set((this.trainingSelection()[choice.key] ?? []).map(Number));
      return options.filter(option =>
        (option.type === 'SKILL' || (option.type === 'TOOL' && /voleur/i.test(option.name)))
        && masteredIds.has(option.id)
        && (rule?.optionIds === undefined || rule.optionIds.includes(option.id))
        && (!existingExpertiseIds.has(option.id) || selectedIds.has(option.id))
      ).map(option => ({ value: option.id, label: option.name }));
    }

    const rule = training.proficiencyRules.find(item => item.ruleId === choice.ruleId);
    return (rule ? options.filter(option => rule.optionIds.includes(option.id)) : options)
      .map(option => ({ value: option.id, label: option.name }));
  }

  protected choiceLabel(choice: TrainingChoice): string {
    if (choice.kind === 'language') return 'Langue supplémentaire';
    const label = this.training()?.proficiencyRules.find(rule => rule.ruleId === choice.ruleId)?.label;
    return label ?? (choice.kind === 'expertise' ? 'Expertise' : 'Maîtrise');
  }

  protected isSelected(choice: TrainingChoice, value: number | string): boolean {
    return (this.trainingSelection()[choice.key] ?? []).includes(value);
  }

  protected toggleChoice(choice: TrainingChoice, value: number | string): void {
    const selected = this.trainingSelection()[choice.key] ?? [];
    const next = selected.includes(value)
      ? selected.filter(item => item !== value)
      : choice.choiceCount === 1 ? [value] : [...selected, value].slice(-choice.choiceCount);
    this.trainingSelection.update(state => ({ ...state, [choice.key]: next }));
  }

  private reloadSpells(preserveSelections = false): void {
    const version = ++this.spellVersion;
    const classOption = this.selectedClassOption();
    const loader = this.spellPreview();
    this.spells.set(null);
    this.spellsLoading.set(false);
    this.spellsError.set(null);
    if (!preserveSelections) {
      this.spellSelection.set({});
      this.toggleReplacement(false);
      this.qualificationOpen.set(false);
      this.qualificationReason.set('');
      this.qualificationError.set(null);
      this.qualificationSuccess.set(null);
      this.qualificationLoading.set(false);
    }
    this.spellSearch.set('');
    this.spellLevelFilter.set(null);
    this.spellSchoolFilter.set(null);
    if (!classOption || !loader || (classOption.subclassRequired && !this.form.subclassId)) return;
    this.spellsLoading.set(true);
    loader(classOption.id, this.form.subclassId).subscribe({
      next: preview => {
        if (version !== this.spellVersion) return;
        this.spells.set(preview);
        this.spellsLoading.set(false);
      },
      error: error => {
        if (version !== this.spellVersion) return;
        this.spellsLoading.set(false);
        this.spellsError.set(error?.error?.message ?? 'Impossible de charger les sorts.');
      },
    });
  }

  private reloadTraining(): void {
    const classOption = this.selectedClassOption();
    const loader = this.trainingPreview();
    const version = ++this.previewVersion;
    this.training.set(null);
    this.trainingLoading.set(false);
    this.trainingSelection.set({});
    this.trainingError.set(null);
    if (!classOption || !loader || (classOption.subclassRequired && !this.form.subclassId)) return;
    this.trainingLoading.set(true);
    loader(classOption.id, this.form.subclassId).subscribe({
      next: training => {
        if (version !== this.previewVersion) return;
        this.training.set(training);
        this.trainingSelection.set(Object.fromEntries(training.choices.map(choice => [choice.key, [...choice.selected]])));
        this.trainingLoading.set(false);
      },
      error: error => {
        if (version !== this.previewVersion) return;
        this.trainingLoading.set(false);
        this.trainingError.set(error?.error?.message ?? 'Impossible de charger les maîtrises.');
      },
    });
  }

  private buildTrainingPayload(): TrainingChoicesPayload {
    const result: TrainingChoicesPayload = {};
    for (const choice of this.trainingChoices()) {
      const selected = this.trainingSelection()[choice.key] ?? [];
      if (!selected.length) continue;
      if (choice.kind === 'proficiency' && choice.ruleId != null) {
        (result.proficiencyChoices ??= []).push({ ruleId: choice.ruleId, proficiencyIds: selected.map(Number) });
      } else if (choice.kind === 'expertise' && choice.ruleId != null) {
        (result.expertiseChoices ??= []).push({ ruleId: choice.ruleId, proficiencyIds: selected.map(Number) });
      } else if (choice.kind === 'language' && choice.sourceKey) {
        (result.languageChoices ??= []).push({ sourceKey: choice.sourceKey, languageSlugs: selected.map(String) });
      }
    }
    return result;
  }

  protected submit(): void {
    if (this.submitting()) return;
    if (!this.spellChoicesComplete()) {
      this.error.set('Complète les choix de sorts enregistrables avant de confirmer. Tu peux désactiver le remplacement facultatif.');
      return;
    }
    const classOption = this.selectedClassOption();

    if (!classOption) {
      this.error.set('La classe est obligatoire.');
      return;
    }

    if (classOption.subclassRequired && !this.form.subclassId) {
      this.error.set('Une sous-classe doit être sélectionnée.');
      return;
    }

    if (this.trainingPreview() && (this.trainingLoading() || this.trainingError() || !this.training())) {
      this.error.set('Attends le chargement des maîtrises avant de confirmer.');
      return;
    }
    if (!this.trainingComplete()) {
      this.error.set('Complète les choix de maîtrise obligatoires.');
      return;
    }

    const advancement = classOption.advancementRequired
      ? this.buildAdvancementPayload()
      : null;

    if (classOption.advancementRequired && !advancement) {
      return;
    }

    const hitPointGain = this.resolveHitPointGain(classOption);

    if (hitPointGain === false) {
      return;
    }

    const payload: LevelUpPayload = {
      ...(this.spellPreview() ? { spellChoices: this.selectedSpellChoices() } : {}),
      classId: classOption.id,
      ...(this.training() ? { training: this.buildTrainingPayload() } : {}),
      subclassId: this.form.subclassId,
      advancement,
      hitPoints: this.form.hitPointMethod === 'average'
        ? { method: 'average' }
        : {
            method: this.form.hitPointMethod,
            gain: hitPointGain,
          },
    };

    this.error.set(null);
    this.submitted.emit(payload);
  }

  protected cancel(): void {
    this.cancelled.emit();
  }

  private applySelectedClassDefaults(): void {
    const classOption = this.selectedClassOption();

    if (!classOption) {
      return;
    }

    if (classOption.currentSubclass) {
      this.form.subclassId = classOption.currentSubclass.id;
    }

    this.form.hitPointMethod = 'average';
    this.form.hitPointGain = classOption.averageHitPointGain;
  }

  private resolveHitPointGain(
    classOption: LevelUpClassOption,
  ): number | false {
    if (this.form.hitPointMethod === 'average') {
      return classOption.averageHitPointGain;
    }

    const gain = this.form.hitPointGain;

    if (
      gain === null
      || !Number.isInteger(gain)
      || gain < 1
      || gain > classOption.hitDie
    ) {
      this.error.set(
        `Le gain brut de PV doit être compris entre 1 et ${classOption.hitDie}.`,
      );

      return false;
    }

    return gain;
  }

  private buildAdvancementPayload():
    | AbilityAdvancementPayload
    | FeatAdvancementPayload
    | null {
    if (this.form.advancementMode === 'feat') {
      const feat = this.selectedFeat();

      if (!feat) {
        this.error.set('Un don doit être sélectionné.');
        return null;
      }

      if (feat.requiresAbilityChoice && !this.form.featAbility) {
        this.error.set(
          'Une caractéristique doit être sélectionnée pour ce don.',
        );
        return null;
      }

      return {
        type: 'feat',
        featId: feat.id,
        ability: this.form.featAbility,
      };
    }

    if (!this.form.firstAbility) {
      this.error.set('Une caractéristique doit être sélectionnée.');
      return null;
    }

    if (this.form.abilityIncreaseMode === 'single') {
      return {
        type: 'ability',
        increases: [{
          ability: this.form.firstAbility,
          value: 2,
        }],
      };
    }

    if (!this.form.secondAbility) {
      this.error.set('La deuxième caractéristique est obligatoire.');
      return null;
    }

    if (this.form.firstAbility === this.form.secondAbility) {
      this.error.set('Choisis deux caractéristiques différentes.');
      return null;
    }

    return {
      type: 'ability',
      increases: [
        {
          ability: this.form.firstAbility,
          value: 1,
        },
        {
          ability: this.form.secondAbility,
          value: 1,
        },
      ],
    };
  }

  private emptyForm(): LevelUpForm {
    return {
      classId: null,
      subclassId: null,
      advancementMode: 'ability',
      abilityIncreaseMode: 'single',
      firstAbility: null,
      secondAbility: null,
      featId: null,
      featAbility: null,
      hitPointMethod: 'average',
      hitPointGain: null,
    };
  }
}

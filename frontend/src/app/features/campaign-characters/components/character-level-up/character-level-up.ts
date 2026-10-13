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
  styleUrl: './character-level-up.scss',
})
export class CharacterLevelUp implements OnInit {
  readonly character = input.required<CharacterProfile>();
  readonly options = input.required<LevelUpOptions>();
  readonly abilities = input.required<AbilityReference[]>();
  readonly feats = input.required<FeatReference[]>();

  readonly submitted = output<LevelUpPayload>();
  readonly cancelled = output<void>();

  readonly submitting = input(false);
  readonly trainingPreview = input<((classId: number, subclassId: number | null) => Observable<TrainingOptions>) | null>(null);
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
  }

  protected subclassChanged(): void {
    this.reloadTraining();
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

import { Component, input, OnInit, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  AbilityAdvancementPayload,
  CharacterProfile,
  FeatAdvancementPayload,
  HitPointGainMethod,
  LevelUpClassOption,
  LevelUpOptions,
  LevelUpPayload,
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
  protected readonly error = signal<string | null>(null);
  protected form: LevelUpForm = this.emptyForm();

  public ngOnInit(): void {
    const defaultClass =
      this.options().classes.find(option => option.currentLevel > 0)
      ?? this.options().classes[0]
      ?? null;

    this.form.classId = defaultClass?.id ?? null;
    this.applySelectedClassDefaults();
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

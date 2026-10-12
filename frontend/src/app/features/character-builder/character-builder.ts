import { CommonModule } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, ElementRef, inject, signal, ViewChild } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { CharacterBuilderIdentity } from './character-builder-identity/character-builder-identity';
import { CharacterBuilderTraining } from './character-builder-training/character-builder-training';
import {
  CharacterBuilderApiService,
  StructuredCharacterResponse,
  BuilderOptions,
  TrainingPayload,
  BuilderSelection,
} from '@core/services/character-builder-api.service';
import {
  AbilityKey,
  AbilityReference,
  ClassReference,
  DndReferenceApiService,
  DndReferenceResponse,
  FeatReference,
  RaceAbilityModifierReference,
  RaceReference,
  BackgroundReference,
  SubclassReference,
} from '@core/services/dnd-reference-api.service';

type CharacterType = 'player' | 'npc';



interface FeatFormChoice {
  featId: number | null;
  ability: AbilityKey | null;
}

@Component({
  selector: 'app-character-builder',
  standalone: true,
  imports: [
    CommonModule,
    ReactiveFormsModule,
    RouterLink,
    CharacterBuilderIdentity,
    CharacterBuilderTraining,
  ],
  templateUrl: './character-builder.html',
  styleUrl: './character-builder.scss',
})
export class CharacterBuilder {

  @ViewChild('creationResult')
  private creationResult?: ElementRef<HTMLElement>;

  private readonly route = inject(ActivatedRoute);
  private readonly formBuilder = inject(FormBuilder);
  private readonly destroyRef = inject(DestroyRef);
  private readonly referenceApi = inject(DndReferenceApiService);
  private readonly builderApi = inject(CharacterBuilderApiService);

  protected readonly campaignId = Number(
    this.route.snapshot.paramMap.get('campaignId'),
  );

  public readonly reference = signal<DndReferenceResponse | null>(null);
  protected readonly loading = signal(true);
  protected readonly currentStep = signal<1 | 2>(1);
  public readonly options = signal<BuilderOptions | null>(null);
  public readonly optionsLoading = signal(false);
  public readonly optionsError = signal<string | null>(null);
  public readonly training = signal<TrainingPayload>(this.emptyTraining());
  public readonly trainingStale = signal(true);
  protected readonly submitting = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly createdCharacter =
    signal<StructuredCharacterResponse | null>(null);

  public readonly racialAbilityChoices =
    signal<Record<number, AbilityKey | null>>({});

  public readonly racialFeatChoices =
    signal<FeatFormChoice[]>([]);

  public readonly form = this.formBuilder.nonNullable.group({
    slug: ['', [
      Validators.required,
      Validators.pattern(/^[a-z0-9]+(?:-[a-z0-9]+)*$/),
    ]],
    name: ['', Validators.required],
    playerName: [''],
    type: ['player' as CharacterType, Validators.required],
    raceId: [0, Validators.min(1)],
    backgroundId: [0],
    classId: [0, Validators.min(1)],
    subclassId: [0],
    abilities: this.formBuilder.nonNullable.group({
      strength: [10, [Validators.required, Validators.min(1), Validators.max(20)]],
      dexterity: [10, [Validators.required, Validators.min(1), Validators.max(20)]],
      constitution: [10, [Validators.required, Validators.min(1), Validators.max(20)]],
      intelligence: [10, [Validators.required, Validators.min(1), Validators.max(20)]],
      wisdom: [10, [Validators.required, Validators.min(1), Validators.max(20)]],
      charisma: [10, [Validators.required, Validators.min(1), Validators.max(20)]],
    }),
  });

  public constructor() {
    this.loadReference();

    this.form.controls.raceId.valueChanges
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(() => this.resetRacialChoices());

    this.form.valueChanges
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(() => this.trainingStale.set(true));

    this.form.controls.classId.valueChanges
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(() => this.form.controls.subclassId.setValue(0));
  }

  public abilities(): AbilityReference[] {
    return this.reference()?.abilities ?? [];
  }

  public races(): RaceReference[] {
    return this.reference()?.races ?? [];
  }

  public selectableRaces(): RaceReference[] {
    return this.races().filter((race) => race.selectable);
  }

  public backgrounds(): BackgroundReference[] {
    return this.reference()?.backgrounds ?? [];
  }

  public classes(): ClassReference[] {
    return this.reference()?.classes ?? [];
  }

  public feats(): FeatReference[] {
    return this.reference()?.feats ?? [];
  }

  public selectedRace(): RaceReference | null {
    const raceId = this.form.controls.raceId.value;

    return this.races().find((race) => race.id === raceId) ?? null;
  }

  public selectedClass(): ClassReference | null {
    const classId = this.form.controls.classId.value;

    return this.classes().find(
      (characterClass) => characterClass.id === classId,
    ) ?? null;
  }

  public availableSubclasses(): SubclassReference[] {
    const classId = this.form.controls.classId.value;

    return (this.reference()?.subclasses ?? []).filter(
      (subclass) => subclass.classId === classId,
    );
  }

  public startingSubclassRequired(): boolean {
    return this.selectedClass()?.subclassSelectionLevel === 1;
  }

  public requiredRacialModifiers(): RaceAbilityModifierReference[] {
    return this.selectedRace()?.abilityModifiers.filter(
      (modifier) => modifier.requiresChoice,
    ) ?? [];
  }

  public fixedRacialModifiers(): RaceAbilityModifierReference[] {
    return this.selectedRace()?.abilityModifiers.filter(
      (modifier) => !modifier.requiresChoice,
    ) ?? [];
  }

  public abilityLabel(abilityKey: AbilityKey | null): string {
    if (abilityKey === null) {
      return 'Au choix';
    }

    return this.abilities().find(
      (ability) => ability.value === abilityKey,
    )?.label ?? abilityKey;
  }

  public selectedFeat(index: number): FeatReference | null {
    const featId = this.racialFeatChoices()[index]?.featId;

    return this.feats().find((feat) => feat.id === featId) ?? null;
  }

  public featAbilities(index: number): AbilityReference[] {
    const feat = this.selectedFeat(index);

    if (!feat) {
      return [];
    }

    return this.abilities().filter(
      (ability) =>
        feat.allowedAbilities.includes(ability.value),
    );
  }

  public setRacialAbilityChoice(
    modifierId: number,
    ability: string,
  ): void {
    this.racialAbilityChoices.update((choices) => ({
      ...choices,
      [modifierId]: ability as AbilityKey,
    }));
  }

  public setFeat(index: number, featId: string): void {
    const parsedFeatId = Number(featId);

    this.racialFeatChoices.update((choices) =>
      choices.map((choice, choiceIndex) =>
        choiceIndex === index
          ? {
              featId: parsedFeatId || null,
              ability: null,
            }
          : choice,
      ),
    );
  }

  public setFeatAbility(index: number, ability: string): void {
    this.racialFeatChoices.update((choices) =>
      choices.map((choice, choiceIndex) =>
        choiceIndex === index
          ? {
              ...choice,
              ability: ability as AbilityKey,
            }
          : choice,
      ),
    );
  }

  public generateSlug(): void {
    if (this.form.controls.slug.dirty) {
      return;
    }

    const slug = this.form.controls.name.value
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');

    this.form.controls.slug.setValue(slug);
  }

  protected nextStep(): void {
    this.error.set(null);
    this.form.markAllAsTouched();
    if (this.form.invalid) {
      this.error.set('Certains champs obligatoires sont absents ou invalides.');
      return;
    }
    if (!this.hasCompletedRacialChoices() || !this.hasCompletedFeatChoices()) {
      this.error.set('Complète les choix raciaux et les dons.');
      return;
    }
    if (this.startingSubclassRequired() && this.form.controls.subclassId.value < 1) {
      this.error.set('Choisis une sous-classe de départ.');
      return;
    }
    this.currentStep.set(2);
    this.refreshOptions(true);
  }

  public refreshOptions(reset = false): void {
    if (reset) {
      this.training.set(this.emptyTraining());
      this.options.set(null);
    }
    this.optionsLoading.set(true);
    this.optionsError.set(null);
    this.builderApi.options(this.campaignId, this.buildSelection())
      .pipe(finalize(() => this.optionsLoading.set(false)))
      .subscribe({
        next: (options) => {
          this.options.set(options);
          this.trainingStale.set(false);
        },
        error: (err: HttpErrorResponse) => {
          this.optionsError.set(err.error?.message ?? 'Prévisualisation indisponible.');
        },
      });
  }

  public setTraining(training: TrainingPayload): void {
    this.training.set(training);
    this.trainingStale.set(true);
  }

  private emptyTraining(): TrainingPayload {
    return {
      proficiencyChoices: [], languageChoices: [], substitutions: [],
      expertiseChoices: [], manualGrants: [],
    };
  }

  private buildSelection(): BuilderSelection {
    const v = this.form.getRawValue();
    return {
      raceId: v.raceId,
      classId: v.classId,
      backgroundId: v.backgroundId || null,
      subclassId: v.subclassId || null,
      level: 1,
      racialFeatChoices: this.racialFeatChoices().filter(c => c.featId !== null)
        .map(c => ({ featId: c.featId as number, ability: c.ability })),
      training: {
        ...this.training(),
        // Le GET refuse les groupes partiels : ne lui transmettre que les groupes complets.
        proficiencyChoices: this.training().proficiencyChoices.filter(c =>
          this.options()?.proficiencyRules.find(r => r.ruleId === c.ruleId)?.choiceCount === c.proficiencyIds.length
        ),
        languageChoices: this.training().languageChoices.filter(c =>
          this.options()?.languageRules.find(r => r.sourceKey === c.sourceKey)?.choiceCount === c.languageSlugs.length
        ),
        expertiseChoices: this.training().expertiseChoices.filter(c =>
          this.options()?.expertiseRules.find(r => r.ruleId === c.ruleId)?.choiceCount === c.proficiencyIds.length
        ),
        // Ne pas envoyer de subventions manuelles incomplètes pendant la preview.
        manualGrants: this.training().manualGrants.filter(g =>
          g.reason.trim().length > 0 && (g.kind === 'LANGUAGE' ? !!g.languageSlug : !!g.proficiencyId)
        ),
      },
    };
  }

  public trainingComplete(): boolean {
    const o = this.options();
    const t = this.training();
    if (!o || this.optionsLoading() || this.optionsError() || this.trainingStale()) return false;
    if (!t.manualGrants.every(g =>
      !!g.reason.trim() && g.reason.length <= 500 &&
      (g.kind === 'LANGUAGE' ? !!g.languageSlug : !!g.proficiencyId)
    )) return false;
    return o.proficiencyRules.every(r =>
      r.choiceCount === 0 || t.proficiencyChoices.find(c => c.ruleId === r.ruleId)?.proficiencyIds.length === r.choiceCount
    ) && o.languageRules.every(r =>
      r.choiceCount === 0 || t.languageChoices.find(c => c.sourceKey === r.sourceKey)?.languageSlugs.length === r.choiceCount
    ) && o.expertiseRules.every(r =>
      r.choiceCount === 0 || t.expertiseChoices.find(c => c.ruleId === r.ruleId)?.proficiencyIds.length === r.choiceCount
    );
  }

  protected previousStep(): void {
    this.error.set(null);
    this.currentStep.set(1);
  }

  protected submit(): void {
    this.error.set(null);
    this.createdCharacter.set(null);
    if (!this.trainingComplete()) {
      this.error.set('Actualise et complète les choix de formation avant de créer le personnage.');
      return;
    }
    this.form.markAllAsTouched();

    if (this.form.invalid) {
      this.error.set(
        'Certains champs obligatoires sont absents ou invalides.',
      );

      return;
    }

    if (!this.hasCompletedRacialChoices()) {
      this.error.set(
        'Tous les choix de caractéristiques raciales doivent être renseignés.',
      );

      return;
    }

    if (!this.hasCompletedFeatChoices()) {
      this.error.set(
        'Tous les dons raciaux et leurs éventuelles caractéristiques doivent être choisis.',
      );

      return;
    }

    if (
      this.startingSubclassRequired()
      && this.form.controls.subclassId.value < 1
    ) {
      this.error.set(
        'Cette classe nécessite de choisir une sous-classe dès le niveau 1.',
      );

      return;
    }

    const rawValue = this.form.getRawValue();

    this.submitting.set(true);

    this.builderApi.create(this.campaignId, {
      slug: rawValue.slug,
      name: rawValue.name,
      playerName: rawValue.playerName || null,
      type: rawValue.type,
      raceId: rawValue.raceId,
      backgroundId: rawValue.backgroundId || null,
      training: this.training(),
      classId: rawValue.classId,
      subclassId: rawValue.subclassId || null,
      abilities: rawValue.abilities,
      racialAbilityChoices: this.requiredRacialModifiers().map(
        (modifier) => ({
          modifierId: modifier.id,
          ability:
            this.racialAbilityChoices()[modifier.id] as AbilityKey,
        }),
      ),
      racialFeatChoices: this.racialFeatChoices().map(
        (choice) => ({
          featId: choice.featId as number,
          ability: choice.ability,
        }),
      ),
    })
      .pipe(finalize(() => this.submitting.set(false)))
      .subscribe({
        next: (response) => {
          this.createdCharacter.set(response.character);

          setTimeout(() => {
            this.creationResult?.nativeElement.scrollIntoView({
              behavior: 'smooth',
              block: 'start',
            });
          });
        },
        error: (error: HttpErrorResponse) => {
          this.error.set(
            error.error?.message
            ?? 'Impossible de créer le personnage.',
          );
        },
      });
  }

  private loadReference(): void {
    this.loading.set(true);

    this.referenceApi.getCampaignReference(this.campaignId)
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (reference) => this.reference.set(reference),
        error: () => {
          this.error.set(
            'Impossible de charger les races, classes et dons.',
          );
        },
      });
  }

  private resetRacialChoices(): void {
    const race = this.selectedRace();

    this.racialAbilityChoices.set(
      Object.fromEntries(
        (race?.abilityModifiers ?? [])
          .filter((modifier) => modifier.requiresChoice)
          .map((modifier) => [modifier.id, null]),
      ),
    );

    this.racialFeatChoices.set(
      Array.from(
        { length: race?.featChoiceCount ?? 0 },
        (): FeatFormChoice => ({
          featId: null,
          ability: null,
        }),
      ),
    );
  }

  private hasCompletedRacialChoices(): boolean {
    const selectedAbilities = this.requiredRacialModifiers().map(
      (modifier) => this.racialAbilityChoices()[modifier.id],
    );

    return selectedAbilities.every(
      (ability): ability is AbilityKey => ability !== null,
    )
      && new Set(selectedAbilities).size === selectedAbilities.length;
  }

  private hasCompletedFeatChoices(): boolean {
    return this.racialFeatChoices().every((choice, index) => {
      const feat = this.selectedFeat(index);

      return feat !== null
        && (!feat.requiresAbilityChoice || choice.ability !== null);
    });
  }
}

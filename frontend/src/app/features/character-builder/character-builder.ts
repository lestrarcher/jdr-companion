import { CommonModule } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, ElementRef, inject, signal, ViewChild } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {
  CharacterBuilderApiService,
  StructuredCharacterResponse,
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

  protected readonly reference = signal<DndReferenceResponse | null>(null);
  protected readonly loading = signal(true);
  protected readonly submitting = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly createdCharacter =
    signal<StructuredCharacterResponse | null>(null);

  protected readonly racialAbilityChoices =
    signal<Record<number, AbilityKey | null>>({});

  protected readonly racialFeatChoices =
    signal<FeatFormChoice[]>([]);

  protected readonly form = this.formBuilder.nonNullable.group({
    slug: ['', [
      Validators.required,
      Validators.pattern(/^[a-z0-9]+(?:-[a-z0-9]+)*$/),
    ]],
    name: ['', Validators.required],
    playerName: [''],
    type: ['player' as CharacterType, Validators.required],
    raceId: [0, Validators.min(1)],
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

    this.form.controls.classId.valueChanges
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(() => this.form.controls.subclassId.setValue(0));
  }

  protected abilities(): AbilityReference[] {
    return this.reference()?.abilities ?? [];
  }

  protected races(): RaceReference[] {
    return this.reference()?.races ?? [];
  }

  protected classes(): ClassReference[] {
    return this.reference()?.classes ?? [];
  }

  protected feats(): FeatReference[] {
    return this.reference()?.feats ?? [];
  }

  protected selectedRace(): RaceReference | null {
    const raceId = this.form.controls.raceId.value;

    return this.races().find((race) => race.id === raceId) ?? null;
  }

  protected selectedClass(): ClassReference | null {
    const classId = this.form.controls.classId.value;

    return this.classes().find(
      (characterClass) => characterClass.id === classId,
    ) ?? null;
  }

  protected availableSubclasses(): SubclassReference[] {
    const classId = this.form.controls.classId.value;

    return (this.reference()?.subclasses ?? []).filter(
      (subclass) => subclass.classId === classId,
    );
  }

  protected startingSubclassRequired(): boolean {
    return this.selectedClass()?.subclassSelectionLevel === 1;
  }

  protected requiredRacialModifiers(): RaceAbilityModifierReference[] {
    return this.selectedRace()?.abilityModifiers.filter(
      (modifier) => modifier.requiresChoice,
    ) ?? [];
  }

  protected fixedRacialModifiers(): RaceAbilityModifierReference[] {
    return this.selectedRace()?.abilityModifiers.filter(
      (modifier) => !modifier.requiresChoice,
    ) ?? [];
  }

  protected abilityLabel(abilityKey: AbilityKey | null): string {
    if (abilityKey === null) {
      return 'Au choix';
    }

    return this.abilities().find(
      (ability) => ability.value === abilityKey,
    )?.label ?? abilityKey;
  }

  protected selectedFeat(index: number): FeatReference | null {
    const featId = this.racialFeatChoices()[index]?.featId;

    return this.feats().find((feat) => feat.id === featId) ?? null;
  }

  protected featAbilities(index: number): AbilityReference[] {
    const feat = this.selectedFeat(index);

    if (!feat) {
      return [];
    }

    return this.abilities().filter(
      (ability) =>
        feat.allowedAbilities.includes(ability.value),
    );
  }

  protected setRacialAbilityChoice(
    modifierId: number,
    ability: string,
  ): void {
    this.racialAbilityChoices.update((choices) => ({
      ...choices,
      [modifierId]: ability as AbilityKey,
    }));
  }

  protected setFeat(index: number, featId: string): void {
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

  protected setFeatAbility(index: number, ability: string): void {
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

  protected generateSlug(): void {
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

  protected submit(): void {
    this.error.set(null);
    this.createdCharacter.set(null);
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

    this.referenceApi.getReference()
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

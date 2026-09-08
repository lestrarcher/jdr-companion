import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  AbilityKey,
  AbilityReference,
  DndReferenceApiService,
  RaceAbilityModifierReference,
  RaceReference,
  SaveRaceAbilityModifierPayload,
  SaveRacePayload,
} from '../../../../core/services/dnd-reference-api.service';

interface RaceForm {
  slug:  string;
  name: string;
  description: string;
  parentRaceId: number | null;
  featChoiceCount: number;
  custom: boolean;
}

interface AbilityModifierForm {
  ability: AbilityKey | null;
  value: number;
  choiceKey: string;
}

@Component({
  selector: 'app-race-manager',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './race-manager.html',
  styleUrl: './race-manager.scss',
})
export class RaceManager {
  private readonly api = inject(DndReferenceApiService);

  protected readonly races = signal<RaceReference[]>([]);
  protected readonly abilities = signal<AbilityReference[]>([]);
  protected readonly selectedRaceId = signal<number | null>(null);
  protected readonly creationModifiers = signal<AbilityModifierForm[]>([]);
  protected readonly loading = signal(true);
  protected readonly submitting = signal(false);
  protected readonly addingModifier = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);
  protected readonly search = signal('');

  protected form: RaceForm = this.emptyRaceForm();
  protected modifierForm: AbilityModifierForm = this.emptyModifierForm();

  protected readonly selectedRace = computed(() => {
    const selectedId = this.selectedRaceId();
    return this.races().find(race => race.id === selectedId) ?? null;
  });

  protected readonly filteredRaces = computed(() => {
    const search = this.search().trim().toLocaleLowerCase('fr');

    if (!search) {
      return this.races();
    }

    return this.races().filter(race =>
      `${race.name} ${race.slug}`.toLocaleLowerCase('fr').includes(search),
    );
  });

  protected readonly availableParentRaces = computed(() => {
    const selectedId = this.selectedRaceId();
    return this.races().filter(race => race.id !== selectedId);
  });

  protected readonly displayedModifiers = computed(() => {
    const race = this.selectedRace();

    if (!race) {
      return [];
    }

    return race.inheritedAbilityModifiers?.length
      ? race.inheritedAbilityModifiers
      : race.abilityModifiers;
  });

  public constructor() {
    this.loadRaces();
  }

  protected selectRace(race: RaceReference): void {
    this.selectedRaceId.set(race.id);
    this.form = {
      slug: race.slug,
      name: race.name,
      description: race.description ?? '',
      parentRaceId: race.parentRace?.id ?? race.parentRaceId ?? null,
      featChoiceCount: race.featChoiceCount,
      custom: race.custom ?? false,
    };
    this.modifierForm = this.emptyModifierForm();
    this.creationModifiers.set([]);
    this.clearMessages();
  }

  protected startCreation(): void {
    this.selectedRaceId.set(null);
    this.form = this.emptyRaceForm();
    this.modifierForm = this.emptyModifierForm();
    this.creationModifiers.set([]);
    this.clearMessages();
  }

  protected save(): void {
    if (this.submitting()) {
      return;
    }

    const payload = this.buildPayload();

    if (!payload.name || !payload.slug) {
      this.error.set('Le nom et le slug sont obligatoires.');
      return;
    }

    this.submitting.set(true);
    this.clearMessages();

    const selectedId = this.selectedRaceId();
    const request = selectedId === null
      ? this.api.createRace(payload)
      : this.api.updateRace(selectedId, {
          slug: payload.slug,
          name: payload.name,
          description: payload.description,
          parentRaceId: payload.parentRaceId,
          featChoiceCount: payload.featChoiceCount,
          custom: payload.custom,
        });

    request.subscribe({
      next: savedRace => {
        this.replaceRace(savedRace);
        this.selectRace(savedRace);
        this.success.set(
          selectedId === null
            ? `${savedRace.name} a bien été créée.`
            : `${savedRace.name} a bien été mise à jour.`,
        );
        this.submitting.set(false);
      },
      error: error => {
        this.error.set(
          error.error?.error ??
          error.error?.message ??
          'Impossible d’enregistrer cette race.',
        );
        this.submitting.set(false);
      },
    });
  }

  protected appendCreationModifier(): void {
    const modifier = this.normalizedModifier(this.modifierForm);

    if (!this.isModifierValid(modifier)) {
      this.error.set(
        'Choisis une caractéristique ou indique une clé pour le bonus au choix.',
      );
      return;
    }

    this.creationModifiers.update(modifiers => [...modifiers, modifier]);
    this.modifierForm = this.emptyModifierForm();
    this.clearMessages();
  }

  protected removeCreationModifier(index: number): void {
    this.creationModifiers.update(modifiers =>
      modifiers.filter((_, modifierIndex) => modifierIndex !== index),
    );
  }

  protected addModifierToExistingRace(): void {
    const raceId = this.selectedRaceId();

    if (raceId === null || this.addingModifier()) {
      return;
    }

    const modifier = this.normalizedModifier(this.modifierForm);

    if (!this.isModifierValid(modifier)) {
      this.error.set(
        'Choisis une caractéristique ou indique une clé pour le bonus au choix.',
      );
      return;
    }

    this.addingModifier.set(true);
    this.clearMessages();

    this.api.addRaceAbilityModifier(raceId, this.toModifierPayload(modifier)).subscribe({
      next: savedRace => {
        this.replaceRace(savedRace);
        this.selectRace(savedRace);
        this.modifierForm = this.emptyModifierForm();
        this.success.set('Le bonus de caractéristique a bien été ajouté.');
        this.addingModifier.set(false);
      },
      error: error => {
        this.error.set(
          error.error?.error ??
          error.error?.message ??
          'Impossible d’ajouter ce bonus.',
        );
        this.addingModifier.set(false);
      },
    });
  }

  protected updateSearch(value: string): void {
    this.search.set(value);
  }

  protected abilityLabel(ability: AbilityKey | null): string {
    if (ability === null) {
      return 'Caractéristique au choix';
    }

    return this.abilities().find(reference => reference.value === ability)?.label ?? ability;
  }

  protected modifierLabel(modifier: RaceAbilityModifierReference): string {
    const ability = modifier.abilityAbbreviation ??
      (modifier.ability ? this.abilityLabel(modifier.ability) : 'Au choix');

    return `+${modifier.value} ${ability}`;
  }

  protected formModifierLabel(modifier: AbilityModifierForm): string {
    const ability = modifier.ability
      ? this.abilityLabel(modifier.ability)
      : `Au choix${modifier.choiceKey ? ` · ${modifier.choiceKey}` : ''}`;

    return `+${modifier.value} ${ability}`;
  }

  protected parentRaceName(race: RaceReference): string | null {
    if (race.parentRace?.name) {
      return race.parentRace.name;
    }

    if (race.parentRaceId) {
      return this.races().find(parent => parent.id === race.parentRaceId)?.name ?? null;
    }

    return null;
  }

  protected trackRace(_: number, race: RaceReference): number {
    return race.id;
  }

  protected trackModifier(
    index: number,
    modifier: RaceAbilityModifierReference,
  ): number {
    return modifier.id || index;
  }

  private loadRaces(): void {
    this.loading.set(true);
    this.error.set(null);

    this.api.getRaces().subscribe({
      next: result => {
        this.races.set(this.sortRaces(result.races));
        this.abilities.set(result.abilities);
        this.loading.set(false);
      },
      error: error => {
        this.error.set(
          error.error?.error ??
          error.error?.message ??
          'Impossible de charger les races.',
        );
        this.loading.set(false);
      },
    });
  }

  private buildPayload(): SaveRacePayload {
    return {
      slug: this.form.slug.trim(),
      name: this.form.name.trim(),
      description: this.form.description.trim() || null,
      parentRaceId: this.form.parentRaceId
        ? Number(this.form.parentRaceId)
        : null,
      featChoiceCount: Number(this.form.featChoiceCount),
      custom: this.form.custom,
      abilityModifiers: this.creationModifiers().map(modifier =>
        this.toModifierPayload(modifier),
      ),
    };
  }

  private normalizedModifier(
    modifier: AbilityModifierForm,
  ): AbilityModifierForm {
    return {
      ability: modifier.ability || null,
      value: Number(modifier.value),
      choiceKey: modifier.choiceKey.trim(),
    };
  }

  private toModifierPayload(
    modifier: AbilityModifierForm,
  ): SaveRaceAbilityModifierPayload {
    return {
      ability: modifier.ability,
      value: modifier.value,
      choiceKey: modifier.ability === null
        ? modifier.choiceKey || null
        : null,
    };
  }

  private isModifierValid(modifier: AbilityModifierForm): boolean {
    return modifier.value > 0 &&
      (modifier.ability !== null || modifier.choiceKey.length > 0);
  }

  private replaceRace(savedRace: RaceReference): void {
    const exists = this.races().some(race => race.id === savedRace.id);

    const updatedRaces = exists
      ? this.races().map(race => race.id === savedRace.id ? savedRace : race)
      : [...this.races(), savedRace];

    this.races.set(this.sortRaces(updatedRaces));
  }

  private sortRaces(races: RaceReference[]): RaceReference[] {
    return [...races].sort((first, second) =>
      first.name.localeCompare(second.name, 'fr'),
    );
  }

  private emptyRaceForm(): RaceForm {
    return {
      slug: '',
      name: '',
      description: '',
      parentRaceId: null,
      featChoiceCount: 0,
      custom: true,
    };
  }

  private emptyModifierForm(): AbilityModifierForm {
    return {
      ability: null,
      value: 1,
      choiceKey: '',
    };
  }

  private clearMessages(): void {
    this.error.set(null);
    this.success.set(null);
  }
}

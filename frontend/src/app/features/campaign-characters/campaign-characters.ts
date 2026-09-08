import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { finalize, forkJoin } from 'rxjs';
import {
  AbilityAdvancementPayload,
  CharacterApiResponse,
  CharacterApiService,
  CharacterProfile,
  FeatAdvancementPayload,
  LevelUpClassOption,
  LevelUpOptions,
  LevelUpPayload,
} from '@core/services/character-api.service';
import {
  AbilityKey,
  DndReferenceApiService,
  DndReferenceResponse,
  FeatReference,
} from '@core/services/dnd-reference-api.service';

type CharacterFilter = 'all' | 'player' | 'npc';
type AdvancementMode = 'ability' | 'feat';
type AbilityIncreaseMode = 'single' | 'double';

interface LevelUpForm {
  classId: number | null;
  subclassId: number | null;
  advancementMode: AdvancementMode;
  abilityIncreaseMode: AbilityIncreaseMode;
  firstAbility: AbilityKey | null;
  secondAbility: AbilityKey | null;
  featId: number | null;
  featAbility: AbilityKey | null;
}

@Component({
  selector: 'app-campaign-characters',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './campaign-characters.html',
  styleUrl: './campaign-characters.scss',
})
export class CampaignCharacters {
  private readonly route = inject(ActivatedRoute);
  private readonly characterApi = inject(CharacterApiService);
  private readonly referenceApi = inject(DndReferenceApiService);

  protected readonly campaignId = Number(
    this.route.snapshot.paramMap.get('campaignId'),
  );

  protected readonly characters = signal<CharacterApiResponse[]>([]);
  protected readonly reference = signal<DndReferenceResponse | null>(null);
  protected readonly filter = signal<CharacterFilter>('all');
  protected readonly selectedCharacterId = signal<number | null>(null);
  protected readonly profile = signal<CharacterProfile | null>(null);
  protected readonly levelUpOptions = signal<LevelUpOptions | null>(null);

  protected readonly loading = signal(true);
  protected readonly profileLoading = signal(false);
  protected readonly submittingLevel = signal(false);
  protected readonly levelUpOpened = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly profileError = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);

  protected search = '';
  protected levelUpForm: LevelUpForm = this.emptyLevelUpForm();

  protected readonly selectedCharacter = computed(() => {
    const selectedId = this.selectedCharacterId();

    return this.characters().find(character => character.id === selectedId) ?? null;
  });

  protected readonly abilities = computed(() => this.reference()?.abilities ?? []);
  protected readonly feats = computed(() => this.reference()?.feats ?? []);

  public constructor() {
    if (!Number.isInteger(this.campaignId) || this.campaignId < 1) {
      this.loading.set(false);
      this.error.set('Identifiant de campagne invalide.');
      return;
    }

    this.loadCharacters();
  }

  protected filteredCharacters(): CharacterApiResponse[] {
    const filter = this.filter();
    const search = this.normalizeSearch(this.search);

    return this.characters().filter(character => {
      if (filter !== 'all' && character.type !== filter) {
        return false;
      }

      if (!search) {
        return true;
      }

      return this.normalizeSearch(
        `${character.name} ${character.playerName ?? ''} ${this.progressionLabel(character)}`,
      ).includes(search);
    });
  }

  protected setFilter(filter: CharacterFilter): void {
    this.filter.set(filter);
  }

  protected countByType(type: CharacterFilter): number {
    if (type === 'all') {
      return this.characters().length;
    }

    return this.characters().filter(character => character.type === type).length;
  }

  protected selectCharacter(character: CharacterApiResponse): void {
    if (this.selectedCharacterId() === character.id && this.profile()) {
      return;
    }

    this.selectedCharacterId.set(character.id);
    this.profile.set(null);
    this.levelUpOptions.set(null);
    this.profileError.set(null);
    this.success.set(null);
    this.levelUpOpened.set(false);
    this.profileLoading.set(true);

    forkJoin({
      profile: this.characterApi.getProfile(this.campaignId, character.id),
      options: this.characterApi.getLevelUpOptions(this.campaignId, character.id),
    })
      .pipe(finalize(() => this.profileLoading.set(false)))
      .subscribe({
        next: result => {
          this.profile.set(result.profile);
          this.levelUpOptions.set(result.options);
        },
        error: error => this.profileError.set(this.errorMessage(error)),
      });
  }

  protected refreshProfile(): void {
    const character = this.selectedCharacter();

    if (character) {
      this.selectedCharacterId.set(null);
      this.selectCharacter(character);
    }
  }

  protected openLevelUp(): void {
    const options = this.levelUpOptions();

    if (!options?.canLevelUp) {
      return;
    }

    const defaultClass =
      options.classes.find(option => option.currentLevel > 0)
      ?? options.classes[0]
      ?? null;

    this.levelUpForm = this.emptyLevelUpForm();
    this.levelUpForm.classId = defaultClass?.id ?? null;
    this.applySelectedClassDefaults();
    this.levelUpOpened.set(true);
    this.profileError.set(null);
    this.success.set(null);
  }

  protected closeLevelUp(): void {
    this.levelUpOpened.set(false);
    this.levelUpForm = this.emptyLevelUpForm();
  }

  protected selectedClassOption(): LevelUpClassOption | null {
    const classId = this.levelUpForm.classId;

    return this.levelUpOptions()?.classes.find(option => option.id === classId) ?? null;
  }

  protected classChanged(): void {
    this.levelUpForm.subclassId = null;
    this.levelUpForm.advancementMode = 'ability';
    this.levelUpForm.abilityIncreaseMode = 'single';
    this.levelUpForm.firstAbility = null;
    this.levelUpForm.secondAbility = null;
    this.levelUpForm.featId = null;
    this.levelUpForm.featAbility = null;
    this.applySelectedClassDefaults();
  }

  protected advancementModeChanged(): void {
    this.levelUpForm.firstAbility = null;
    this.levelUpForm.secondAbility = null;
    this.levelUpForm.featId = null;
    this.levelUpForm.featAbility = null;
  }

  protected abilityIncreaseModeChanged(): void {
    this.levelUpForm.firstAbility = null;
    this.levelUpForm.secondAbility = null;
  }

  protected featChanged(): void {
    this.levelUpForm.featAbility = null;
  }

  protected selectedFeat(): FeatReference | null {
    const featId = this.levelUpForm.featId;

    return this.feats().find(feat => feat.id === featId) ?? null;
  }

  protected featAbilities(): Array<{
    value: AbilityKey;
    label: string;
    abbreviation: string;
  }> {
    const feat = this.selectedFeat();

    if (!feat?.requiresAbilityChoice) {
      return [];
    }

    if (feat.allowedAbilities.length === 0) {
      return this.abilities();
    }

    return this.abilities().filter(ability =>
      feat.allowedAbilities.includes(ability.value),
    );
  }

  protected submitLevelUp(): void {
    const profile = this.profile();
    const classOption = this.selectedClassOption();

    if (!profile || !classOption) {
      this.profileError.set('La classe est obligatoire.');
      return;
    }

    if (classOption.subclassRequired && !this.levelUpForm.subclassId) {
      this.profileError.set('Une sous-classe doit être sélectionnée.');
      return;
    }

    const advancement = classOption.advancementRequired
      ? this.buildAdvancementPayload()
      : null;

    if (classOption.advancementRequired && !advancement) {
      return;
    }

    const payload: LevelUpPayload = {
      classId: classOption.id,
      subclassId: this.levelUpForm.subclassId,
      advancement,
    };

    this.submittingLevel.set(true);
    this.profileError.set(null);
    this.success.set(null);

    this.characterApi
      .levelUp(this.campaignId, profile.id, payload)
      .pipe(finalize(() => this.submittingLevel.set(false)))
      .subscribe({
        next: response => {
          this.profile.set(response.character);
          this.updateCharacterInList(response.character);
          this.success.set(response.message);
          this.levelUpOpened.set(false);
          this.levelUpForm = this.emptyLevelUpForm();
          this.reloadLevelUpOptions(response.character.id);
        },
        error: error => this.profileError.set(this.errorMessage(error)),
      });
  }

  protected typeLabel(character: CharacterApiResponse): string {
    return character.type === 'player'
      ? 'Personnage joueur'
      : 'Personnage non-joueur';
  }

  protected progressionLabel(character: CharacterApiResponse): string {
    if (character.totalLevel > 0 && character.classLevels.length > 0) {
      const classes = new Map<
        number,
        { name: string; level: number; subclass: string | null }
      >();

      for (const classLevel of character.classLevels) {
        const existing = classes.get(classLevel.classId);

        if (existing) {
          ++existing.level;

          if (classLevel.subclassName) {
            existing.subclass = classLevel.subclassName;
          }

          continue;
        }

        classes.set(classLevel.classId, {
          name: classLevel.className,
          level: 1,
          subclass: classLevel.subclassName,
        });
      }

      return [...classes.values()]
        .map(entry => {
          const classLabel = `${entry.name} ${entry.level}`;
          return entry.subclass ? `${classLabel} · ${entry.subclass}` : classLabel;
        })
        .join(' / ');
    }

    return this.legacyProgressionLabel(character);
  }

  protected isStructured(character: CharacterApiResponse): boolean {
    return character.totalLevel > 0 && character.classLevels.length > 0;
  }

  protected modifierLabel(modifier: number): string {
    return modifier >= 0 ? `+${modifier}` : `${modifier}`;
  }

  protected rechargeLabel(rechargeType: string): string {
    return {
      short_rest: 'Repos court ou long',
      long_rest: 'Repos long',
      dawn: 'À l’aube',
      manual: 'Manuelle',
      none: 'Aucune',
      never: 'Jamais',
    }[rechargeType] ?? rechargeType.replaceAll('_', ' ');
  }

  protected featureSourceLabel(sourceType: string): string {
    return {
      class: 'Classe',
      subclass: 'Sous-classe',
      race: 'Race',
      feat: 'Don',
    }[sourceType] ?? sourceType;
  }

  private loadCharacters(): void {
    this.loading.set(true);
    this.error.set(null);

    forkJoin({
      characters: this.characterApi.list(this.campaignId),
      reference: this.referenceApi.getReference(),
    })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: result => {
          const characters = [...result.characters].sort((first, second) =>
            first.name.localeCompare(second.name, 'fr'),
          );

          this.characters.set(characters);
          this.reference.set(result.reference);

          const firstStructured =
            characters.find(character => this.isStructured(character))
            ?? characters[0]
            ?? null;

          if (firstStructured) {
            this.selectCharacter(firstStructured);
          }
        },
        error: error => this.error.set(this.errorMessage(error)),
      });
  }

  private reloadLevelUpOptions(characterId: number): void {
    this.characterApi
      .getLevelUpOptions(this.campaignId, characterId)
      .subscribe({
        next: options => this.levelUpOptions.set(options),
        error: error => this.profileError.set(this.errorMessage(error)),
      });
  }

  private updateCharacterInList(profile: CharacterProfile): void {
    this.characters.update(characters =>
      characters.map(character =>
        character.id === profile.id
          ? {
              ...character,
              race: profile.race,
              totalLevel: profile.totalLevel,
              proficiencyBonus: profile.proficiencyBonus,
              classLevels: profile.classLevels,
            }
          : character,
      ),
    );
  }

  private applySelectedClassDefaults(): void {
    const classOption = this.selectedClassOption();

    if (classOption?.currentSubclass) {
      this.levelUpForm.subclassId = classOption.currentSubclass.id;
    }
  }

  private buildAdvancementPayload():
    | AbilityAdvancementPayload
    | FeatAdvancementPayload
    | null {
    if (this.levelUpForm.advancementMode === 'feat') {
      const feat = this.selectedFeat();

      if (!feat) {
        this.profileError.set('Un don doit être sélectionné.');
        return null;
      }

      if (feat.requiresAbilityChoice && !this.levelUpForm.featAbility) {
        this.profileError.set(
          'Une caractéristique doit être sélectionnée pour ce don.',
        );
        return null;
      }

      return {
        type: 'feat',
        featId: feat.id,
        ability: this.levelUpForm.featAbility,
      };
    }

    if (!this.levelUpForm.firstAbility) {
      this.profileError.set('Une caractéristique doit être sélectionnée.');
      return null;
    }

    if (this.levelUpForm.abilityIncreaseMode === 'single') {
      return {
        type: 'ability',
        increases: [
          {
            ability: this.levelUpForm.firstAbility,
            value: 2,
          },
        ],
      };
    }

    if (!this.levelUpForm.secondAbility) {
      this.profileError.set('La deuxième caractéristique est obligatoire.');
      return null;
    }

    if (this.levelUpForm.firstAbility === this.levelUpForm.secondAbility) {
      this.profileError.set('Choisis deux caractéristiques différentes.');
      return null;
    }

    return {
      type: 'ability',
      increases: [
        {
          ability: this.levelUpForm.firstAbility,
          value: 1,
        },
        {
          ability: this.levelUpForm.secondAbility,
          value: 1,
        },
      ],
    };
  }

  private emptyLevelUpForm(): LevelUpForm {
    return {
      classId: null,
      subclassId: null,
      advancementMode: 'ability',
      abilityIncreaseMode: 'single',
      firstAbility: null,
      secondAbility: null,
      featId: null,
      featAbility: null,
    };
  }

  private legacyProgressionLabel(character: CharacterApiResponse): string {
    const className = this.readString(
      character.definition,
      ['className', 'classLabel', 'class'],
    );
    const level = character.definition['level'];

    if (className && typeof level === 'number') {
      return `${className} ${level}`;
    }

    return className ?? 'Configuration historique';
  }

  private readString(
    definition: Record<string, unknown>,
    keys: string[],
  ): string | null {
    for (const key of keys) {
      const value = definition[key];

      if (typeof value === 'string' && value.trim() !== '') {
        return value;
      }
    }

    return null;
  }

  private normalizeSearch(value: string): string {
    return value
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .trim();
  }

  private errorMessage(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
      return error.error?.message ?? 'La requête a échoué.';
    }

    return 'Une erreur inattendue est survenue.';
  }
}

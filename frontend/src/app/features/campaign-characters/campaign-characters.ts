import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { finalize, forkJoin } from 'rxjs';
import {
  CharacterApiResponse,
  CharacterApiService,
  CharacterProfile as CharacterProfileModel,
  LevelUpOptions,
  LevelUpResponse,
  UpdateHitPointHistoryResponse,
  LevelUpPayload,
} from '@core/services/character-api.service';
import {
  DndReferenceApiService,
  DndReferenceResponse,
  ProgressionReference,
} from '@core/services/dnd-reference-api.service';
import { CharacterHitPointHistory } from './components/character-hit-point-history/character-hit-point-history';
import { CharacterLevelUp } from './components/character-level-up/character-level-up';
import { CharacterProfile } from './components/character-profile/character-profile';
import { CharacterRoster } from './components/character-roster/character-roster';
import { CharacterProgressions } from './components/character-progressions/character-progressions';

type CharacterEditor =
  | 'level-up'
  | 'hit-points'
  | 'progressions'
  | null;

@Component({
  selector: 'app-campaign-characters',
  standalone: true,
  imports: [
    RouterLink,
    CharacterRoster,
    CharacterProfile,
    CharacterLevelUp,
    CharacterHitPointHistory,
    CharacterProgressions,
  ],
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
  protected readonly levelUpSubmitting = signal(false);
  protected readonly progressionSubmitting = signal(false);
  protected readonly characters = signal<CharacterApiResponse[]>([]);
  protected readonly reference = signal<DndReferenceResponse | null>(null);
  protected readonly selectedCharacterId = signal<number | null>(null);
  protected readonly profile = signal<CharacterProfileModel | null>(null);
  protected readonly levelUpOptions = signal<LevelUpOptions | null>(null);
  protected readonly activeEditor = signal<CharacterEditor>(null);
  protected readonly progressions = signal<ProgressionReference[]>([]);
  protected readonly loading = signal(true);
  protected readonly profileLoading = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly profileError = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);

  protected readonly abilities = computed(
    () => this.reference()?.abilities ?? [],
  );

  protected readonly feats = computed(
    () => this.reference()?.feats ?? [],
  );

  public constructor() {
    if (!Number.isInteger(this.campaignId) || this.campaignId < 1) {
      this.loading.set(false);
      this.error.set('Identifiant de campagne invalide.');
      return;
    }

    this.loadCharacters();
  }

  protected submitLevelUp(
    payload: LevelUpPayload,
  ): void {
    const characterId =
      this.selectedCharacterId();

    if (characterId === null) {
      return;
    }

    this.levelUpSubmitting.set(true);

    this.profileError.set(null);

    this.characterApi
      .levelUp(
        this.campaignId,
        characterId,
        payload,
      )
      .pipe(
        finalize(() =>
          this.levelUpSubmitting.set(false),
        ),
      )
      .subscribe({
        next: response =>
          this.levelUpCompleted(response),
        error: error =>
          this.profileError.set(
            this.errorMessage(error),
          ),
      });
  }

  protected selectCharacter(character: CharacterApiResponse): void {
    if (
      this.selectedCharacterId() === character.id
      && this.profile()
    ) {
      return;
    }

    this.selectedCharacterId.set(character.id);
    this.loadCharacterDetails(character.id);
  }

  protected refreshProfile(): void {
    const characterId = this.selectedCharacterId();

    if (characterId !== null) {
      this.loadCharacterDetails(characterId);
    }
  }

  protected openEditor(editor: Exclude<CharacterEditor, null>): void {
    this.activeEditor.set(editor);
    this.profileError.set(null);
    this.success.set(null);
  }

  protected closeEditor(): void {
    this.activeEditor.set(null);
  }

  protected levelUpCompleted(response: LevelUpResponse): void {
    this.applyUpdatedProfile(response.character);
    this.success.set(response.message);
    this.activeEditor.set(null);
    this.reloadLevelUpOptions(response.character.id);
  }

  protected hitPointHistoryCompleted(
    response: UpdateHitPointHistoryResponse,
  ): void {
    this.applyUpdatedProfile(response.character);
    this.success.set(response.message);
    this.activeEditor.set(null);
  }

  private loadCharacters(): void {
    this.loading.set(true);
    this.error.set(null);

    forkJoin({
      characters: this.characterApi.list(this.campaignId),
      reference: this.referenceApi.getReference(),
      progressions: this.referenceApi.getProgressions(),
    })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: result => {
          const characters = [...result.characters].sort(
            (first, second) =>
              first.name.localeCompare(second.name, 'fr'),
          );

          this.characters.set(characters);
          this.reference.set(result.reference);
          this.progressions.set(result.progressions.progressions);

          const firstCharacter =
            characters.find(character => this.isStructured(character))
            ?? characters[0]
            ?? null;

          if (firstCharacter) {
            this.selectedCharacterId.set(firstCharacter.id);
            this.loadCharacterDetails(firstCharacter.id);
          }
        },
        error: error => this.error.set(this.errorMessage(error)),
      });
  }

  private loadCharacterDetails(characterId: number): void {
    this.profileLoading.set(true);
    this.profileError.set(null);
    this.success.set(null);
    this.activeEditor.set(null);
    this.profile.set(null);
    this.levelUpOptions.set(null);

    forkJoin({
      profile: this.characterApi.getProfile(
        this.campaignId,
        characterId,
      ),
      options: this.characterApi.getLevelUpOptions(
        this.campaignId,
        characterId,
      ),
    })
      .pipe(finalize(() => this.profileLoading.set(false)))
      .subscribe({
        next: result => {
          this.profile.set(result.profile);
          this.levelUpOptions.set(result.options);
        },
        error: error =>
          this.profileError.set(this.errorMessage(error)),
      });
  }

  private reloadLevelUpOptions(characterId: number): void {
    this.characterApi
      .getLevelUpOptions(this.campaignId, characterId)
      .subscribe({
        next: options => this.levelUpOptions.set(options),
        error: error =>
          this.profileError.set(this.errorMessage(error)),
      });
  }

  private applyUpdatedProfile(
    profile: CharacterProfileModel,
  ): void {
    this.profile.set(profile);

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

  private isStructured(
    character: CharacterApiResponse,
  ): boolean {
    return (
      character.totalLevel > 0
      && character.classLevels.length > 0
    );
  }

  private errorMessage(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
      return error.error?.message ?? 'La requête a échoué.';
    }

    return 'Une erreur inattendue est survenue.';
  }

  protected addProgression(
    progressionId: number,
  ): void {
    const characterId =
      this.selectedCharacterId();

    if (characterId === null) {
      return;
    }

    this.progressionSubmitting.set(true);
    this.profileError.set(null);
    this.success.set(null);

    this.characterApi
      .addProgression(
        this.campaignId,
        characterId,
        progressionId,
      )
      .pipe(
        finalize(() =>
          this.progressionSubmitting.set(false),
        ),
      )
      .subscribe({
        next: () => {
          this.success.set('Progression attribuée.');
          this.loadCharacterDetails(characterId);
        },
        error: error =>
          this.profileError.set(
            this.errorMessage(error),
          ),
      });
  }

  protected removeProgression(
    progressionId: number,
  ): void {
    const characterId =
      this.selectedCharacterId();

    if (characterId === null) {
      return;
    }

    this.progressionSubmitting.set(true);
    this.profileError.set(null);
    this.success.set(null);

    this.characterApi
      .removeProgression(
        this.campaignId,
        characterId,
        progressionId,
      )
      .pipe(
        finalize(() =>
          this.progressionSubmitting.set(false),
        ),
      )
      .subscribe({
        next: () => {
          this.success.set('Progression retirée.');
          this.loadCharacterDetails(characterId);
        },
        error: error =>
          this.profileError.set(
            this.errorMessage(error),
          ),
      });
  }
}

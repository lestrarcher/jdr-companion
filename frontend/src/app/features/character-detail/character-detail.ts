import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { finalize, forkJoin } from 'rxjs';
import {
  CharacterApiService,
  CharacterProfile as CharacterProfileModel,
  LevelUpOptions,
  UpdateHitPointHistoryResponse,
} from '@core/services/character-api.service';
import {
  DndReferenceApiService,
  ProgressionReference,
} from '@core/services/dnd-reference-api.service';
import { CharacterHitPointHistory } from '../campaign-characters/components/character-hit-point-history/character-hit-point-history';
import { CharacterProgressions } from '../campaign-characters/components/character-progressions/character-progressions';
import { CharacterTraining } from '../campaign-characters/components/character-training/character-training';
import { CharacterSheet } from './components/character-sheet/character-sheet';
import { CharacterAbilitiesResources } from './components/character-abilities-resources/character-abilities-resources';
import { CharacterEquipment } from './components/character-equipment/character-equipment';
import { CharacterHistory } from './components/character-history/character-history';
import { CharacterSpells } from './components/character-spells/character-spells';

type CharacterEditor =
  | 'training'
  | 'hit-points'
  | 'progressions'
  | null;

@Component({
  selector: 'app-character-detail',
  standalone: true,
  imports: [
    RouterLink,
    CharacterHitPointHistory,
    CharacterProgressions,
    CharacterTraining,
    CharacterSheet,
    CharacterAbilitiesResources,
    CharacterEquipment,
    CharacterHistory,
    CharacterSpells,
  ],
  templateUrl: './character-detail.html',
  styleUrl: './character-detail.scss',
})
export class CharacterDetail {
  protected readonly tabs = [
    { id: 'sheet', label: 'Fiche' },
    { id: 'equipment', label: 'Équipement' },
    { id: 'spells', label: 'Sorts' },
    { id: 'history', label: 'Historique' },
    { id: 'resources', label: 'Capacités et ressources' },
  ] as const;
  protected readonly activeTab = signal<string>('sheet');
  protected readonly classSummary = computed(() => {
    const classes = new Map<number, { name: string; level: number; subclasses: Set<string> }>();
    for (const level of this.profile()?.classLevels ?? []) {
      const entry = classes.get(level.classId) ?? { name: level.className, level: 0, subclasses: new Set<string>() };
      entry.level++;
      if (level.subclassName) entry.subclasses.add(level.subclassName);
      classes.set(level.classId, entry);
    }
    return [...classes.values()].map(entry =>
      entry.name + ' ' + entry.level + (entry.subclasses.size ? ' · ' + [...entry.subclasses].join(', ') : '')
    ).join(' / ');
  });
  protected tabKeydown(event: KeyboardEvent, index: number): void {
    let target: number;
    switch (event.key) {
      case 'ArrowRight': target = (index + 1) % this.tabs.length; break;
      case 'ArrowLeft': target = (index + this.tabs.length - 1) % this.tabs.length; break;
      case 'Home': target = 0; break;
      case 'End': target = this.tabs.length - 1; break;
      default: return;
    }
    event.preventDefault();
    this.activeTab.set(this.tabs[target].id);
    const button = event.currentTarget as HTMLButtonElement;
    button.parentElement?.querySelectorAll<HTMLButtonElement>('[role="tab"]')[target]?.focus();
  }
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly characterApi = inject(CharacterApiService);
  private readonly referenceApi = inject(DndReferenceApiService);

  protected readonly campaignId = Number(
    this.route.snapshot.paramMap.get('campaignId'),
  );
  protected readonly progressionSubmitting = signal(false);
  protected readonly profile = signal<CharacterProfileModel | null>(null);
  protected readonly levelUpOptions = signal<LevelUpOptions | null>(null);
  protected readonly activeEditor = signal<CharacterEditor>(null);
  protected readonly progressions = signal<ProgressionReference[]>([]);
  protected readonly profileLoading = signal(false);
  protected readonly profileError = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);

  protected readonly characterId = Number(this.route.snapshot.paramMap.get('characterId'));

  public constructor() {
    if (!Number.isSafeInteger(this.campaignId) || this.campaignId < 1
      || !Number.isSafeInteger(this.characterId) || this.characterId < 1) {
      this.profileError.set('Identifiant de campagne ou de personnage invalide.');
      return;
    }
    this.loadCharacterDetails(this.characterId);
  }
  protected openLevelUp(): void {
    void this.router.navigate(['/campaigns', this.campaignId, 'characters', this.characterId, 'level-up']);
  }

  protected refreshProfile(): void {
    const characterId = this.characterId;

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

  protected hitPointHistoryCompleted(
    response: UpdateHitPointHistoryResponse,
  ): void {
    this.applyUpdatedProfile(response.character);
    this.success.set(response.message);
    this.activeEditor.set(null);
  }

  private loadCharacterDetails(characterId: number, message: string | null = null): void {
    if (!Number.isSafeInteger(this.campaignId) || this.campaignId < 1 || !Number.isSafeInteger(characterId) || characterId < 1) return;
    this.profileLoading.set(true);
    this.profileError.set(null);
    this.success.set(message);
    this.activeEditor.set(null);
    this.profile.set(null);
    this.levelUpOptions.set(null);

    forkJoin({
      progressions: this.referenceApi.getCampaignProgressions(this.campaignId),
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
          this.progressions.set(result.progressions.progressions);
          this.profile.set(result.profile);
          this.levelUpOptions.set(result.options);
        },
        error: error =>
          this.profileError.set(this.errorMessage(error)),
      });
  }

  private applyUpdatedProfile(
    profile: CharacterProfileModel,
  ): void {
    this.profile.set(profile);

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
      this.characterId;

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
          this.loadCharacterDetails(characterId, 'Progression attribuée.');
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
      this.characterId;

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
          this.loadCharacterDetails(characterId, 'Progression retirée.');
        },
        error: error =>
          this.profileError.set(
            this.errorMessage(error),
          ),
      });
  }
}

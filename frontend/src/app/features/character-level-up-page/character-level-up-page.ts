import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, OnInit, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { finalize, forkJoin, map } from 'rxjs';
import {
  CharacterApiService,
  CharacterProfile,
  LevelUpOptions,
  LevelUpPayload,
  LevelUpSpellPreview,
} from '@core/services/character-api.service';
import { CharacterLevelUp } from '../campaign-characters/components/character-level-up/character-level-up';

@Component({
  selector: 'app-character-level-up-page',
  standalone: true,
  imports: [RouterLink, CharacterLevelUp],
  templateUrl: './character-level-up-page.html',
  styleUrl: './character-level-up-page.scss',
})
export class CharacterLevelUpPage implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly api = inject(CharacterApiService);

  protected readonly campaignId = Number(this.route.snapshot.paramMap.get('campaignId'));
  protected readonly characterId = Number(this.route.snapshot.paramMap.get('characterId'));
  protected readonly profile = signal<CharacterProfile | null>(null);
  protected readonly options = signal<LevelUpOptions | null>(null);
  protected readonly loading = signal(true);
  protected readonly submitting = signal(false);
  protected readonly error = signal<string | null>(null);

  protected readonly loadTraining = (classId: number, subclassId: number | null) =>
    this.api.getTrainingOptions(this.characterId, classId, subclassId);

  protected readonly qualifySpell = (policyId: number, acquisitionId: number, ruleId: number, reason: string) =>
    this.api.qualifySpell(this.characterId, policyId, acquisitionId, { ruleId, reason });

  protected readonly loadSpells = (classId: number, subclassId: number | null) =>
    this.api.getLevelUpOptions(this.campaignId, this.characterId, classId, subclassId)
      .pipe(map(options => options.spellPreview));

  protected readonly backRoute = ['/campaigns', this.campaignId, 'characters', this.characterId];

  ngOnInit(): void {
    if (!Number.isSafeInteger(this.campaignId) || this.campaignId < 1
      || !Number.isSafeInteger(this.characterId) || this.characterId < 1) {
      this.error.set('Identifiant de campagne ou de personnage invalide.');
      this.loading.set(false);
      return;
    }

    forkJoin({
      profile: this.api.getProfile(this.campaignId, this.characterId),
      options: this.api.getLevelUpOptions(this.campaignId, this.characterId),
    }).pipe(finalize(() => this.loading.set(false))).subscribe({
      next: ({ profile, options }) => {
        this.profile.set(profile);
        this.options.set(options);
      },
      error: error => this.error.set(this.errorMessage(error)),
    });
  }

  protected submitLevelUp(payload: LevelUpPayload): void {
    if (this.submitting()) return;
    this.submitting.set(true);
    this.error.set(null);
    this.api.levelUp(this.campaignId, this.characterId, payload)
      .pipe(finalize(() => this.submitting.set(false)))
      .subscribe({
        next: () => { void this.router.navigate(this.backRoute); },
        error: error => this.error.set(this.errorMessage(error)),
      });
  }

  protected cancel(): void {
    void this.router.navigate(this.backRoute);
  }

  private errorMessage(error: unknown): string {
    return error instanceof HttpErrorResponse
      ? error.error?.message ?? 'Impossible de charger la montée de niveau.'
      : 'Une erreur inattendue est survenue.';
  }
}

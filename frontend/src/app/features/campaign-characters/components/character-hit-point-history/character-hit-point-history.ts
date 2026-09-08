import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, input, OnInit, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  CharacterApiService,
  CharacterClassLevel,
  CharacterProfile,
  HitPointGainMethod,
  HitPointHistoryEntryPayload,
  UpdateHitPointHistoryResponse,
} from '@core/services/character-api.service';
import { finalize } from 'rxjs';

interface HitPointHistoryFormEntry {
  position: number;
  className: string;
  hitDie: number;
  method: HitPointGainMethod;
  gain: number | null;
}

@Component({
  selector: 'app-character-hit-point-history',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './character-hit-point-history.html',
  styleUrl: './character-hit-point-history.scss',
})
export class CharacterHitPointHistory implements OnInit {
  private readonly characterApi = inject(CharacterApiService);

  readonly campaignId = input.required<number>();
  readonly character = input.required<CharacterProfile>();

  readonly completed = output<UpdateHitPointHistoryResponse>();
  readonly cancelled = output<void>();

  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected entries: HitPointHistoryFormEntry[] = [];

  public ngOnInit(): void {
    this.entries = this.character().classLevels.map(level =>
      this.createEntry(level),
    );
  }

  protected methodChanged(entry: HitPointHistoryFormEntry): void {
    if (entry.position === 1) {
      entry.method = 'first_level';
      entry.gain = entry.hitDie;
      return;
    }

    entry.gain = entry.method === 'average'
      ? this.averageHitPointGain(entry.hitDie)
      : null;
  }

  protected save(): void {
    const levels: HitPointHistoryEntryPayload[] = [];

    for (const entry of this.entries) {
      if (!this.isEntryValid(entry)) {
        this.error.set(
          `Le gain brut du niveau ${entry.position} doit être compris entre 1 et ${entry.hitDie}.`,
        );
        return;
      }

      levels.push(
        entry.method === 'average' || entry.method === 'first_level'
          ? {
              position: entry.position,
              method: entry.method,
            }
          : {
              position: entry.position,
              method: entry.method,
              gain: entry.gain!,
            },
      );
    }

    this.saving.set(true);
    this.error.set(null);

    this.characterApi
      .updateHitPointHistory(
        this.campaignId(),
        this.character().id,
        { levels },
      )
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: response => this.completed.emit(response),
        error: error => this.error.set(this.errorMessage(error)),
      });
  }

  protected cancel(): void {
    this.cancelled.emit();
  }

  protected totalBaseValue(): number {
    return this.entries.reduce(
      (total, entry) => total + (entry.gain ?? 0),
      0,
    );
  }

  protected projectedMaximumValue(): number {
    const character = this.character();

    return Math.max(
      character.totalLevel,
      this.totalBaseValue()
        + character.hitPoints.constitutionModifier * character.totalLevel,
    );
  }

  protected averageHitPointGain(hitDie: number): number {
    return Math.floor(hitDie / 2) + 1;
  }

  private createEntry(
    level: CharacterClassLevel,
  ): HitPointHistoryFormEntry {
    const hitDie = this.levelHitDie(level);

    if (level.position === 1) {
      return {
        position: level.position,
        className: level.className,
        hitDie,
        method: 'first_level',
        gain: hitDie,
      };
    }

    const method = level.hitPointGainMethod ?? 'average';

    return {
      position: level.position,
      className: level.className,
      hitDie,
      method,
      gain: level.hitPointGain ?? (
        method === 'average'
          ? this.averageHitPointGain(hitDie)
          : null
      ),
    };
  }

  private levelHitDie(level: CharacterClassLevel): number {
    if (level.hitDie) {
      return level.hitDie;
    }

    return this.character().classSummary.find(
      classEntry => classEntry.classId === level.classId,
    )?.hitDie ?? 0;
  }

  private isEntryValid(entry: HitPointHistoryFormEntry): boolean {
    if (
      entry.method === 'average'
      || entry.method === 'first_level'
    ) {
      return true;
    }

    return (
      entry.gain !== null
      && Number.isInteger(entry.gain)
      && entry.gain >= 1
      && entry.gain <= entry.hitDie
    );
  }

  private errorMessage(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
      return error.error?.message
        ?? 'L’enregistrement des points de vie a échoué.';
    }

    return 'Une erreur inattendue est survenue.';
  }
}

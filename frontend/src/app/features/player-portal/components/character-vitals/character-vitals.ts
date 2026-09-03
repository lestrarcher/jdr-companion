import {
  Component,
  inject,
  input,
} from '@angular/core';
import { FormsModule } from '@angular/forms';

import { Character } from '@core/models/character.model';
import { CharacterStateService } from '@core/services/character-state.service';

@Component({
  selector: 'app-character-vitals',
  imports: [FormsModule],
  templateUrl: './character-vitals.html',
  styleUrl: './character-vitals.scss',
})
export class CharacterVitals {
  private readonly characterStateService =
    inject(CharacterStateService);

  readonly character = input.required<Character>();

  protected hitPointAmount = 0;

  protected applyDamage(): void {
    if (this.hitPointAmount <= 0) {
      return;
    }

    this.characterStateService.applyDamage(
      this.hitPointAmount,
    );

    this.hitPointAmount = 0;
  }

  protected heal(): void {
    if (this.hitPointAmount <= 0) {
      return;
    }

    this.characterStateService.heal(
      this.hitPointAmount,
    );

    this.hitPointAmount = 0;
  }

  protected changeTemporaryHitPoints(change: number): void {
    this.characterStateService.adjustTemporaryHitPoints(change);
  }

  protected spendHitDie(hitDicePoolId: string): void {
    this.characterStateService.adjustHitDice(
      hitDicePoolId,
      -1,
    );
  }
}

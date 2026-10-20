import { Component, input } from '@angular/core';
import { CharacterProfile } from '@core/services/character-api.service';

@Component({
  selector: 'app-character-history',
  standalone: true,
  templateUrl: './character-history.html',
  styleUrl: './character-history.scss',
})
export class CharacterHistory {
  readonly character = input.required<CharacterProfile>();
}

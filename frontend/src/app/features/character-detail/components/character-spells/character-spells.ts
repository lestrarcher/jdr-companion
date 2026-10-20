import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { CharacterProfile } from '@core/services/character-api.service';

@Component({
  selector: 'app-character-spells',
  standalone: true,
  imports: [RouterLink],
  templateUrl: './character-spells.html',
  styleUrl: './character-spells.scss',
})
export class CharacterSpells {
  readonly character = input.required<CharacterProfile>();
  readonly campaignId = input.required<number>();
}

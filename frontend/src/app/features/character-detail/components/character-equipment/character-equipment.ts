import { Component, input } from '@angular/core';
import { CharacterProfile } from '@core/services/character-api.service';

@Component({
  selector: 'app-character-equipment',
  standalone: true,
  templateUrl: './character-equipment.html',
  styleUrl: './character-equipment.scss',
})
export class CharacterEquipment {
  readonly character = input.required<CharacterProfile>();
}

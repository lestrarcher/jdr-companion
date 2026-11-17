import { Component, input, output } from '@angular/core';

@Component({
  selector: 'app-character-inspiration',
  templateUrl: './character-inspiration.html',
  styleUrl: './character-inspiration.scss',
})
export class CharacterInspiration {
  readonly active = input(false);
  readonly readonly = input(false);
  readonly saving = input(false);
  readonly spend = output<void>();
}

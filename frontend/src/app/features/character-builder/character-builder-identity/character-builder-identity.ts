import { Component, input } from '@angular/core';
import { ReactiveFormsModule } from '@angular/forms';
import type { CharacterBuilder } from '../character-builder';

@Component({
  selector: 'app-character-builder-identity',
  standalone: true,
  imports: [ReactiveFormsModule],
  templateUrl: './character-builder-identity.html',
  styleUrl: '../character-builder-shared.scss',
})
export class CharacterBuilderIdentity {
  readonly vm = input.required<CharacterBuilder>();
}

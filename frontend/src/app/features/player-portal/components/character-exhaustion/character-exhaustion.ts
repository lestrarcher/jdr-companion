import { Component, input, output } from '@angular/core';

@Component({
  selector: 'app-character-exhaustion',
  templateUrl: './character-exhaustion.html',
  styleUrl: './character-exhaustion.scss',
})
export class CharacterExhaustion {
  readonly effects = [
    { level: 1, label: "Désavantage aux tests de caractéristique" },
    { level: 2, label: "Vitesse réduite de moitié" },
    { level: 3, label: "Désavantage aux attaques et sauvegardes" },
    { level: 4, label: "Maximum de PV réduit de moitié" },
    { level: 5, label: "Vitesse réduite à 0" },
    { level: 6, label: "Mort" },
  ] as const;

  readonly level = input(0);
  readonly readonly = input(false);
  readonly saving = input(false);
  readonly change = output<number>();
}

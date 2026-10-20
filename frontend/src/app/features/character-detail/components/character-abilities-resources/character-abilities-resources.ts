import { Component, input } from '@angular/core';
import { CharacterProfile } from '@core/services/character-api.service';

@Component({
  selector: 'app-character-abilities-resources',
  standalone: true,
  templateUrl: './character-abilities-resources.html',
  styleUrl: './character-abilities-resources.scss',
})
export class CharacterAbilitiesResources {
  readonly character = input.required<CharacterProfile>();

  protected rechargeLabel(type: string): string {
    const labels: Record<string, string> = {
      'short-rest': 'Repos court ou long',
      short_rest: 'Repos court ou long',
      'long-rest': 'Repos long',
      long_rest: 'Repos long',
      dawn: 'À l’aube',
      manual: 'Recharge manuelle',
      none: 'Aucune recharge',
      never: 'Jamais',
    };

    return labels[type] ?? type.replaceAll(/[-_]/g, ' ');
  }

  protected activationLabel(type: string): string {
    const labels: Record<string, string> = {
      passive: 'Passif',
      action: 'Action',
      bonus_action: 'Action bonus',
      'bonus-action': 'Action bonus',
      reaction: 'Réaction',
      free_action: 'Action libre',
      'free-action': 'Action libre',
      special: 'Spécial',
    };

    return labels[type] ?? type.replaceAll(/[-_]/g, ' ');
  }

  protected featureSourceLabel(type: string): string {
    const labels: Record<string, string> = {
      class: 'Classe',
      subclass: 'Sous-classe',
      race: 'Race',
      feat: 'Don',
      progression: 'Progression',
    };

    return labels[type] ?? type;
  }
}

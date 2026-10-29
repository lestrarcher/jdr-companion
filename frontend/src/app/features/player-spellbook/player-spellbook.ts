import { Component, inject } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { PlayerSpells } from '../player-portal/components/player-spells/player-spells';

@Component({
  selector: 'app-player-spellbook',
  imports: [RouterLink, PlayerSpells],
  template: `
    <main class="spellbook-page">
      <a class="back-link" [routerLink]="['..']" [queryParams]="{ tab: 'spells' }">← Retour aux sorts</a>
      <app-player-spells mode="spellbook" [spells]="[]" [accessToken]="accessToken" />
      <!-- Reserved for a future functional "Recopier un sort" control. No inactive button. -->
      <div class="spellbook-copy-slot"><ng-content select="[spellbook-copy]" /></div>
    </main>
  `,
  styles: [`
    :host { display: block; min-height: 100dvh; background: #11151e; color: #ece8df; }
    .spellbook-page { max-width: 72rem; margin: auto; padding: clamp(1rem, 4vw, 2rem); }
    .back-link { display: inline-flex; align-items: center; min-height: 44px; margin-bottom: 1rem; color: #ddc496; }
    .back-link:focus-visible { outline: 2px solid #e5c78e; outline-offset: 3px; }
    .spellbook-copy-slot:empty { display: none; }
  `],
})
export class PlayerSpellbook {
  readonly accessToken = inject(ActivatedRoute).snapshot.paramMap.get('accessToken') ?? '';
}

import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { CharacterApiService, CharacterProfile } from '@core/services/character-api.service';
import { SpellInitialization } from '../character-detail/components/character-spells/spell-initialization';

@Component({
  selector: 'app-spell-initialization-page', standalone: true,
  imports: [RouterLink, SpellInitialization],
  template: `
    <main class="page">
      <a class="back" [routerLink]="['/campaigns', campaignId, 'characters', characterId]">← Retour à la fiche</a>
      @if (error()) { <p role="alert">{{ error() }}</p> }
      @if (character(); as profile) {
        <p class="context">{{ profile.name }} · Niveau {{ profile.totalLevel }}</p>
        <app-spell-initialization [characterId]="characterId" [totalLevel]="profile.totalLevel" />
      } @else if (!error()) { <p role="status">Chargement du personnage…</p> }
    </main>
  `,
  styles: [`:host{display:block;color:#eee9df}.page{width:min(100% - 2rem,1200px);margin:1.5rem auto 4rem}.back{display:inline-block;color:#e9c780;text-decoration:none;padding:.7rem 0;margin-bottom:1rem}.context{color:#b8ab96;font-size:.85rem;margin:0 0 1rem}`],
})
export class SpellInitializationPage {
  private readonly route = inject(ActivatedRoute);
  private readonly api = inject(CharacterApiService);
  readonly campaignId = Number(this.route.snapshot.paramMap.get('campaignId'));
  readonly characterId = Number(this.route.snapshot.paramMap.get('characterId'));
  readonly character = signal<CharacterProfile | null>(null);
  readonly error = signal('');
  constructor() {
    if (!Number.isSafeInteger(this.campaignId) || this.campaignId < 1 || !Number.isSafeInteger(this.characterId) || this.characterId < 1) {
      this.error.set('Identifiant de personnage invalide.'); return;
    }
    this.api.getProfile(this.campaignId, this.characterId).subscribe({
      next: profile => this.character.set(profile),
      error: () => this.error.set('Impossible de charger le personnage.'),
    });
  }
}

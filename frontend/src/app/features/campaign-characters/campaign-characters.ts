import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import { CharacterApiResponse, CharacterApiService } from '@core/services/character-api.service';
import { CharacterRoster } from './components/character-roster/character-roster';

@Component({
  selector: 'app-campaign-characters',
  standalone: true,
  imports: [RouterLink, CharacterRoster],
  templateUrl: './campaign-characters.html',
  styleUrl: './campaign-characters.scss',
})
export class CampaignCharacters {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly characterApi = inject(CharacterApiService);
  protected readonly campaignId = Number(this.route.snapshot.paramMap.get('campaignId'));
  protected readonly characters = signal<CharacterApiResponse[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  constructor() {
    if (!Number.isSafeInteger(this.campaignId) || this.campaignId < 1) {
      this.loading.set(false);
      this.error.set('Identifiant de campagne invalide.');
      return;
    }
    this.characterApi.list(this.campaignId).pipe(finalize(() => this.loading.set(false))).subscribe({
      next: characters => this.characters.set([...characters].sort((a, b) => a.name.localeCompare(b.name, 'fr'))),
      error: error => this.error.set(error instanceof HttpErrorResponse
        ? error.error?.message ?? 'La requête a échoué.' : 'Une erreur inattendue est survenue.'),
    });
  }

  protected selectCharacter(character: CharacterApiResponse): void {
    void this.router.navigate(['/campaigns', this.campaignId, 'characters', character.id]);
  }
}
import {
  inject,
  Injectable,
} from '@angular/core';

import {
  forkJoin,
  map,
  Observable,
  of,
  switchMap,
} from 'rxjs';

import {
  CampaignConfig,
} from '@core/models/campaign.model';

import {
  Character,
} from '@core/models/character.model';

import {
  CharacterApiResponse,
  CharacterApiService,
} from '@core/services/character-api.service';

import {
  CharacterSessionStateApiResponse,
  CharacterSessionStateApiService,
} from '@core/services/character-session-state-api.service';

import {
  toCharacterSessionStatePayload,
  toCreateCharacterPayload,
} from '@core/mappers/character-api.mapper';

export interface ImportedCharacterResult {
  characterId: number;
  characterName: string;
  characterSlug: string;
  accessToken: string | null;

  currentHitPoints: number;
  temporaryHitPoints: number;
  maximumHitPoints: number;
}

@Injectable({
  providedIn: 'root',
})
export class CampaignBootstrapService {
  private readonly characterApi =
    inject(CharacterApiService);

  private readonly characterSessionStateApi =
    inject(CharacterSessionStateApiService);

  synchronizeCharacters(
    campaign: CampaignConfig,
    campaignId: number,
    sessionId: number,
  ): Observable<ImportedCharacterResult[]> {
    return this.characterApi
      .list(campaignId)
      .pipe(
        switchMap((existingCharacters) => {
          const synchronizations =
            campaign.characters.map(
              (character) => {
                const slug =
                  this.getCharacterSlug(
                    character.id,
                  );

                const existingCharacter =
                  existingCharacters.find(
                    (candidate) =>
                      candidate.slug === slug,
                  );

                const characterRequest$: Observable<CharacterApiResponse> =
                  existingCharacter
                    ? of(existingCharacter)
                    : this.characterApi.create(
                        campaignId,
                        toCreateCharacterPayload(
                          character,
                          campaign,
                        ),
                      );

                return characterRequest$.pipe(
                  switchMap((apiCharacter) =>
                    this.characterSessionStateApi
                      .create(
                        sessionId,
                        apiCharacter.id,
                        toCharacterSessionStatePayload(
                          character,
                        ),
                      )
                      .pipe(
                        map((sessionState) =>
                          this.toImportedCharacterResult(
                            apiCharacter,
                            sessionState,
                            character,
                          ),
                        ),
                      ),
                  ),
                );
              },
            );

          if (synchronizations.length === 0) {
            return of([]);
          }

          return forkJoin(synchronizations);
        }),
      );
  }

  private getCharacterSlug(
    characterId: string,
  ): string {
    return characterId.replace(
      /^character-/,
      '',
    );
  }

  private toImportedCharacterResult(
    character: CharacterApiResponse,
    sessionState: CharacterSessionStateApiResponse,
    configuredCharacter: Character,
  ): ImportedCharacterResult {
    return {
      characterId: character.id,
      characterName: character.name,
      characterSlug: character.slug,

      accessToken:
        sessionState.accessToken ?? null,

      currentHitPoints:
        sessionState.state.hitPoints.current,

      temporaryHitPoints:
        sessionState.state.hitPoints.temporary,

      maximumHitPoints:
        configuredCharacter.hitPoints.maximum,
    };
  }
}

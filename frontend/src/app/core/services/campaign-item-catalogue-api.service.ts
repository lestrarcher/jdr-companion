import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

export interface CampaignItemRaritySettings {
  rarities: string[];
}

@Injectable({ providedIn: 'root' })
export class CampaignItemCatalogueApiService {
  private readonly http = inject(HttpClient);

  getRarities(campaignId: number): Observable<CampaignItemRaritySettings> {
    return this.http.get<CampaignItemRaritySettings>(`/api/campaigns/${campaignId}/item-catalogue/rarities`);
  }

  saveRarities(campaignId: number, rarities: string[]): Observable<CampaignItemRaritySettings> {
    return this.http.put<CampaignItemRaritySettings>(`/api/campaigns/${campaignId}/item-catalogue/rarities`, { rarities });
  }
}

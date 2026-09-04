import {
  inject,
  Injectable,
} from '@angular/core';
import { map } from 'rxjs';

import { CampaignConfig } from '@core/models/campaign.model';
import {
  CampaignApiResponse,
  CampaignApiService,
  CampaignConfigurationKey,
} from '@core/services/campaign-api.service';
import { STRAHD_CAMPAIGN } from '@data/campaigns/strahd.config';

@Injectable({
  providedIn: 'root',
})
export class CampaignConfigurationRegistryService {
  private readonly campaignApi =
    inject(CampaignApiService);

  private readonly configurations:
    Partial<
      Record<
        CampaignConfigurationKey,
        CampaignConfig
      >
    > = {
      strahd: STRAHD_CAMPAIGN,
    };

  getCampaign(
    campaignId: number,
  ) {
    return this.campaignApi.list().pipe(
      map((campaigns) => {
        const campaign = campaigns.find(
          (candidate) =>
            candidate.id === campaignId,
        );

        if (!campaign) {
          throw new Error(
            `Campagne ${campaignId} introuvable.`,
          );
        }

        return {
          campaign,
          configuration:
            this.getConfiguration(
              campaign.configurationKey,
            ),
        };
      }),
    );
  }

  getConfiguration(
    key: CampaignConfigurationKey,
  ): CampaignConfig {
    const configuration =
      this.configurations[key];

    if (!configuration) {
      throw new Error(
        `La configuration "${key}" n’est pas encore disponible.`,
      );
    }

    return configuration;
  }

  hasConfiguration(
    key: CampaignConfigurationKey,
  ): boolean {
    return Boolean(this.configurations[key]);
  }
}

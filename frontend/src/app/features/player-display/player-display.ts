import { Component, inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';

import { LiveSessionService } from '@core/services/live-session.service';
import { STRAHD_CAMPAIGN } from '@data/campaigns/strahd.config';
import { AmbientFog } from '@shared/components/ambient-fog/ambient-fog';

import { MainDisplay } from './components/main-display/main-display';
import { MemorialPanel } from './components/memorial-panel/memorial-panel';
import { QuestsPanel } from './components/quests-panel/quests-panel';
import { TipBar } from './components/tip-bar/tip-bar';
import { WorldHeader } from './components/world-header/world-header';

@Component({
  selector: 'app-player-display',
  imports: [
    WorldHeader,
    MainDisplay,
    QuestsPanel,
    MemorialPanel,
    TipBar,
    AmbientFog,
  ],
  templateUrl: './player-display.html',
  styleUrl: './player-display.scss',
})
export class PlayerDisplay {
  private readonly liveSessionService = inject(LiveSessionService);
  private readonly route = inject(ActivatedRoute);

  protected readonly campaign = STRAHD_CAMPAIGN;
  protected readonly liveState = this.liveSessionService.state;

  constructor() {
    const campaignId = this.route.snapshot.paramMap.get('campaignId');
    const sessionId = this.route.snapshot.paramMap.get('sessionId');

    if (!campaignId || !sessionId) {
      throw new Error('Identifiants de campagne ou de session manquants.');
    }

    if (campaignId !== this.campaign.id) {
      throw new Error(`Campagne inconnue : ${campaignId}`);
    }

    this.liveSessionService.initialize(this.campaign, sessionId);
  }
}

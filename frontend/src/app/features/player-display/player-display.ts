import { Component } from '@angular/core';

import { MainDisplay } from './components/main-display/main-display';
import { MemorialPanel } from './components/memorial-panel/memorial-panel';
import { QuestsPanel } from './components/quests-panel/quests-panel';
import { TipBar } from './components/tip-bar/tip-bar';
import { WorldHeader } from './components/world-header/world-header';
import { AmbientFog } from '../../shared/components/ambient-fog/ambient-fog';

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
export class PlayerDisplay {}

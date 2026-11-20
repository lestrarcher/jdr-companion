import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';

export type PlayerPortalTab = 'overview' | 'status' | 'statistics' | 'features' | 'spells' | 'possessions' | 'wild-shapes';

@Component({
  selector: 'app-player-portal-tabs',
  standalone: true,
  templateUrl: './player-portal-tabs.html',
  styleUrl: './player-portal-tabs.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PlayerPortalTabs {
  readonly activeTab = input.required<PlayerPortalTab>();
  readonly desktopView = input.required<boolean>();
  readonly hasWildShapes = input.required<boolean>();
  readonly tabSelected = output<PlayerPortalTab>();
}

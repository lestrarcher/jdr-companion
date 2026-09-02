import { Routes } from '@angular/router';

const DEFAULT_CAMPAIGN_ID = 'campaign-strahd-01';
const DEFAULT_SESSION_ID = 'session-samedi';

const DEFAULT_DISPLAY_ROUTE =
  `campaigns/${DEFAULT_CAMPAIGN_ID}/sessions/${DEFAULT_SESSION_ID}/display`;

const DEFAULT_CONTROL_ROUTE =
  `campaigns/${DEFAULT_CAMPAIGN_ID}/sessions/${DEFAULT_SESSION_ID}/control`;

export const routes: Routes = [
  {
    path: 'campaigns/:campaignId/sessions/:sessionId/display',
    loadComponent: () =>
      import('./features/player-display/player-display').then(
        (component) => component.PlayerDisplay,
      ),
  },
  {
    path: 'campaigns/:campaignId/sessions/:sessionId/control',
    loadComponent: () =>
      import('./features/control-dashboard/control-dashboard').then(
        (component) => component.ControlDashboard,
      ),
  },
  {
    path: 'display',
    redirectTo: DEFAULT_DISPLAY_ROUTE,
    pathMatch: 'full',
  },
  {
    path: 'control',
    redirectTo: DEFAULT_CONTROL_ROUTE,
    pathMatch: 'full',
  },
  {
    path: '',
    redirectTo: DEFAULT_DISPLAY_ROUTE,
    pathMatch: 'full',
  },
  {
    path: '**',
    redirectTo: DEFAULT_DISPLAY_ROUTE,
  },
];

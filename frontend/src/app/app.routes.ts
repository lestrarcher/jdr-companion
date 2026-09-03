import { Routes } from '@angular/router';

import { authGuard } from '@core/guards/auth-guard';

export const routes: Routes = [
  {
    path: 'login',
    loadComponent: () =>
      import('./features/login/login').then(
        (component) => component.Login,
      ),
  },
  {
    path: 'campaigns',
    loadComponent: () =>
      import(
        './features/campaign-list/campaign-list'
      ).then(
        (component) =>
          component.CampaignList,
      ),
    canActivate: [authGuard],
  },
  {
    path: 'campaigns/:campaignId',
    loadComponent: () =>
      import(
        './features/campaign-detail/campaign-detail'
      ).then(
        (component) =>
          component.CampaignDetail,
      ),
    canActivate: [authGuard],
  },
  {
    path: 'campaigns/:campaignId/sessions/:sessionId/control',
    loadComponent: () =>
      import(
        './features/control-dashboard/control-dashboard'
      ).then(
        (component) =>
          component.ControlDashboard,
      ),
    canActivate: [authGuard],
  },
  {
    path: 'campaigns/:campaignId/sessions/:sessionId/display',
    loadComponent: () =>
      import(
        './features/player-display/player-display'
      ).then(
        (component) =>
          component.PlayerDisplay,
      ),
    canActivate: [authGuard],
  },
  {
    path: 'campaigns/:campaignId/sessions/:sessionId/player/:accessToken',
    loadComponent: () =>
      import(
        './features/player-portal/player-portal'
      ).then(
        (component) =>
          component.PlayerPortal,
      ),
  },
  {
    path: '',
    pathMatch: 'full',
    redirectTo: 'campaigns',
  },
  {
    path: '**',
    redirectTo: 'campaigns',
  },
];

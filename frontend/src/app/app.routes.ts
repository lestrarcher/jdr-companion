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

  /*
   * Ces deux interfaces restent volontairement
   * en dehors du layout MJ.
   */
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

  /*
   * Toutes les pages privées du MJ utilisent
   * désormais la même enveloppe de navigation.
   */
  {
    path: '',
    loadComponent: () =>
      import(
        './features/mj-layout/mj-layout'
      ).then(
        (component) =>
          component.MjLayout,
      ),
    canActivate: [authGuard],

    children: [
      {
        path: 'campaigns',
        loadComponent: () =>
          import(
            './features/campaign-list/campaign-list'
          ).then(
            (component) =>
              component.CampaignList,
          ),
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
      },
      {
        path: '',
        pathMatch: 'full',
        redirectTo: 'campaigns',
      },
    ],
  },

  {
    path: '**',
    redirectTo: 'campaigns',
  },
];

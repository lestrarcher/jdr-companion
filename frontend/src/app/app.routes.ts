import { Routes } from '@angular/router';
import { authGuard } from '@core/guards/auth-guard';

export const routes: Routes = [
  {
    path: 'login',
    loadComponent: () =>
      import('./features/login/login').then(component => component.Login),
  },
  {
    path: 'campaigns/:campaignId/sessions/:sessionId/display',
    loadComponent: () =>
      import('./features/player-display/player-display').then(
        component => component.PlayerDisplay,
      ),
    canActivate: [authGuard],
  },
  {
    path: 'campaigns/:campaignId/sessions/:sessionId/player/:accessToken/level-up',
    loadComponent: () =>
      import(
        './features/player-level-up/player-level-up'
      ).then(
        (component) =>
          component.PlayerLevelUp,
      ),
  },
  {
    path: 'campaigns/:campaignId/sessions/:sessionId/player/:accessToken',
    loadComponent: () =>
      import('./features/player-portal/player-portal').then(
        component => component.PlayerPortal,
      ),
  },
  {
    path: '',
    loadComponent: () =>
      import('./features/mj-layout/mj-layout').then(component => component.MjLayout),
    canActivate: [authGuard],
    children: [
      {
        path: 'campaigns',
        loadComponent: () =>
          import('./features/campaign-list/campaign-list').then(
            component => component.CampaignList,
          ),
      },
      {
        path: 'campaigns/:campaignId/characters',
        loadComponent: () =>
          import('./features/campaign-characters/campaign-characters').then(
            component => component.CampaignCharacters,
          ),
      },
      {
        path: 'campaigns/:campaignId/characters/new',
        loadComponent: () =>
          import('./features/character-builder/character-builder').then(
            component => component.CharacterBuilder,
          ),
      },
      {
        path: 'campaigns/:campaignId',
        loadComponent: () =>
          import('./features/campaign-detail/campaign-detail').then(
            component => component.CampaignDetail,
          ),
      },
      {
        path: 'campaigns/:campaignId/sessions/:sessionId/control',
        loadComponent: () =>
          import('./features/control-dashboard/control-dashboard').then(
            component => component.ControlDashboard,
          ),
      },
      {
        path: 'dnd/reference',
        loadComponent: () =>
          import('./features/dnd-reference/feature-manager/feature-manager').then(
            component => component.FeatureManager,
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

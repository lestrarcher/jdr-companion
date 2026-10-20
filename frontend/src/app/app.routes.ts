import { Routes } from '@angular/router';
import { authGuard } from '@core/guards/auth-guard';
import { preparationGuard } from '@core/guards/preparation-guard';

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
        path: 'dnd/spell-policies',
        loadComponent: () => import('./features/spell-policies/spell-policies').then(component => component.SpellPolicies),
        canDeactivate: [preparationGuard],
      },
      {
        path: 'custom-content',
        redirectTo: 'dnd/reference', pathMatch: 'full',
      },
      {
        path: 'dnd/reference',
        loadComponent: () =>
          import('./features/dnd-reference/feature-manager/feature-manager')
            .then(component => component.FeatureManager),
      },
        {
          path: 'dnd/reference/official',
          data: { referenceScope: 'official' },
          loadComponent: () => import('./features/dnd-reference/feature-manager/feature-manager').then(component => component.FeatureManager),
        },
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
        path: 'campaigns/:campaignId/characters/:characterId/spell-substitutions',
        canDeactivate: [preparationGuard],
        loadComponent: () => import('./features/character-spellbook/spell-substitutions').then(component => component.SpellSubstitutions),
      },
      {
        path: 'campaigns/:campaignId/characters/:characterId/spell-revision',
        canDeactivate: [preparationGuard],
        loadComponent: () => import('./features/character-spellbook/spell-revision').then(component => component.SpellRevision),
      },
      {
        path: 'campaigns/:campaignId/characters/:characterId/spellbook',
        canDeactivate: [preparationGuard],
        loadComponent: () => import('./features/character-spellbook/character-spellbook').then(component => component.CharacterSpellbook),
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
        canDeactivate: [preparationGuard],
        loadComponent: () =>
          import('./features/control-dashboard/control-dashboard').then(
            component => component.ControlDashboard,
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

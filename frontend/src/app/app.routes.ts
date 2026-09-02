import { Routes } from '@angular/router';

export const routes: Routes = [
  {
    path: 'display',
    loadComponent: () =>
      import('./features/player-display/player-display').then(
        (component) => component.PlayerDisplay,
      ),
  },
  {
    path: '',
    pathMatch: 'full',
    redirectTo: 'display',
  },
  {
    path: '**',
    redirectTo: 'display',
  },
];

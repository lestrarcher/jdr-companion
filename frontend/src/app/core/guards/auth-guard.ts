import { inject } from '@angular/core';
import {
  CanActivateFn,
  Router,
} from '@angular/router';
import { map } from 'rxjs';

import { AuthService } from '@core/services/auth.service';

export const authGuard: CanActivateFn = (
  _route,
  state,
) => {
  const authService = inject(AuthService);
  const router = inject(Router);

  if (authService.isAuthenticated()) {
    return true;
  }

  return authService.checkSession().pipe(
    map((authenticated) => {
      if (authenticated) {
        return true;
      }

      return router.createUrlTree(
        ['/login'],
        {
          queryParams: {
            returnUrl: state.url,
          },
        },
      );
    }),
  );
};

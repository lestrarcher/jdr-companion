import {
  computed,
  inject,
  Injectable,
  signal,
} from '@angular/core';
import { HttpClient } from '@angular/common/http';
import {
  catchError,
  map,
  Observable,
  of,
  tap,
} from 'rxjs';

import {
  AuthResponse,
  AuthUser,
  LoginCredentials,
} from '@core/models/auth.model';

@Injectable({
  providedIn: 'root',
})
export class AuthService {
  private readonly http = inject(HttpClient);

  private readonly currentUser =
    signal<AuthUser | null>(null);

  readonly user = this.currentUser.asReadonly();

  readonly isAuthenticated = computed(
    () => this.currentUser() !== null,
  );

  login(
    credentials: LoginCredentials,
  ): Observable<AuthUser> {
    return this.http
      .post<AuthResponse>(
        '/api/login',
        credentials,
      )
      .pipe(
        map((response) => response.user),
        tap((user) => {
          this.currentUser.set(user);
        }),
      );
  }

  checkSession(): Observable<boolean> {
    return this.http
      .get<AuthResponse>('/api/me')
      .pipe(
        tap((response) => {
          this.currentUser.set(
            response.user,
          );
        }),

        map(() => true),

        catchError(() => {
          this.currentUser.set(null);

          return of(false);
        }),
      );
  }
}

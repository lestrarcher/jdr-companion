import {
  Component,
  inject,
  signal,
} from '@angular/core';
import {
  FormBuilder,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import {
  ActivatedRoute,
  Router,
} from '@angular/router';

import { AuthService } from '@core/services/auth.service';

@Component({
  selector: 'app-login',
  imports: [ReactiveFormsModule],
  templateUrl: './login.html',
  styleUrl: './login.scss',
})
export class Login {
  private readonly formBuilder =
    inject(FormBuilder);

  private readonly authService =
    inject(AuthService);

  private readonly router =
    inject(Router);

  private readonly route =
    inject(ActivatedRoute);

  protected readonly submitting =
    signal(false);

  protected readonly errorMessage =
    signal<string | null>(null);

  protected readonly loginForm =
    this.formBuilder.nonNullable.group({
      email: [
        '',
        [
          Validators.required,
          Validators.email,
        ],
      ],

      password: [
        '',
        Validators.required,
      ],
    });

  protected submit(): void {
    if (
      this.loginForm.invalid ||
      this.submitting()
    ) {
      this.loginForm.markAllAsTouched();

      return;
    }

    this.submitting.set(true);
    this.errorMessage.set(null);

    this.authService
      .login(
        this.loginForm.getRawValue(),
      )
      .subscribe({
        next: () => {
          const requestedUrl =
            this.route.snapshot.queryParamMap
              .get('returnUrl');

          const returnUrl =
            requestedUrl?.startsWith('/')
              ? requestedUrl
              : '/control';

          void this.router.navigateByUrl(
            returnUrl,
          );
        },

        error: () => {
          this.submitting.set(false);

          this.errorMessage.set(
            'Adresse email ou mot de passe incorrect.',
          );
        },
      });
  }
}

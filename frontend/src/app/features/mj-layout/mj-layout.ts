import { Component, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {
  ActivatedRoute,
  NavigationEnd,
  Router,
  RouterLink,
  RouterOutlet,
} from '@angular/router';
import {
  filter,
  startWith,
} from 'rxjs';

interface Breadcrumb {
  label: string;
  url?: string;
}

@Component({
  selector: 'app-mj-layout',
  imports: [
    RouterLink,
    RouterOutlet,
  ],
  templateUrl: './mj-layout.html',
  styleUrl: './mj-layout.scss',
})
export class MjLayout {
  protected readonly breadcrumbs =
    signal<Breadcrumb[]>([]);

  constructor(
    private readonly router: Router,
    private readonly route: ActivatedRoute,
  ) {
    this.router.events
      .pipe(
        filter(
          (event) =>
            event instanceof NavigationEnd,
        ),
        startWith(null),
        takeUntilDestroyed(),
      )
      .subscribe(() => {
        this.updateBreadcrumbs();
      });
  }

  private updateBreadcrumbs(): void {
    const childRoute =
      this.getDeepestChildRoute();

    const campaignId =
      childRoute.snapshot.paramMap.get(
        'campaignId',
      );

    const sessionId =
      childRoute.snapshot.paramMap.get(
        'sessionId',
      );

    const breadcrumbs: Breadcrumb[] = [];

    if (!campaignId) {
      breadcrumbs.push({
        label: 'Campagnes',
      });

      this.breadcrumbs.set(breadcrumbs);
      return;
    }

    breadcrumbs.push({
      label: 'Campagnes',
      url: '/campaigns',
    });

    if (!sessionId) {
      breadcrumbs.push({
        label: 'Sessions',
      });

      this.breadcrumbs.set(breadcrumbs);
      return;
    }

    breadcrumbs.push({
      label: 'Sessions',
      url: `/campaigns/${campaignId}`,
    });

    breadcrumbs.push({
      label: 'Control',
    });

    this.breadcrumbs.set(breadcrumbs);
  }

  private getDeepestChildRoute():
    ActivatedRoute {
    let currentRoute = this.route;

    while (currentRoute.firstChild) {
      currentRoute =
        currentRoute.firstChild;
    }

    return currentRoute;
  }
}

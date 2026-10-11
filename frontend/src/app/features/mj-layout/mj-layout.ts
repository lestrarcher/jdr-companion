import { Component, computed, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {
  ActivatedRoute,
  NavigationEnd,
  Router,
  RouterLink,
  RouterLinkActive,
  RouterOutlet,
} from '@angular/router';
import { filter, finalize, forkJoin, startWith, Subscription } from 'rxjs';
import { CampaignConfigurationRegistryService } from '@core/services/campaign-configuration-registry.service';
import { GameSessionApiService } from '@core/services/game-session-api.service';

interface Breadcrumb {
  label: string;
  url?: string;
}

@Component({
  selector: 'app-mj-layout',
  imports: [RouterLink, RouterLinkActive, RouterOutlet],
  templateUrl: './mj-layout.html',
  styleUrl: './mj-layout.scss',
})
export class MjLayout {
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly campaignRegistry = inject(CampaignConfigurationRegistryService);
  private readonly gameSessionApi = inject(GameSessionApiService);
  private navigationRequest?: Subscription;

  protected readonly breadcrumbs = signal<Breadcrumb[]>([]);
  protected readonly campaignId = signal<number | null>(null);
  protected readonly sessionId = signal<number | null>(null);
  protected readonly navigationLoading = signal(false);
  protected readonly sessionsUrl = computed(() =>
    this.campaignId() ? `/campaigns/${this.campaignId()}` : null,
  );

  constructor() {
    inject(DestroyRef).onDestroy(() => this.navigationRequest?.unsubscribe());
    this.router.events
      .pipe(
        filter(event => event instanceof NavigationEnd),
        startWith(null),
        takeUntilDestroyed(),
      )
      .subscribe(() => this.updateNavigationContext());
  }

  private updateNavigationContext(): void {
    this.navigationRequest?.unsubscribe();
    const childRoute = this.getDeepestChildRoute();
    const snapshot = childRoute.snapshot;
    // The initial child snapshot can be pending while the layout is constructed.
    if (!snapshot) return;
    const rawCampaignId = Number(snapshot.paramMap.get('campaignId'));
    const rawSessionId = Number(snapshot.paramMap.get('sessionId'));
    const campaignId =
      Number.isInteger(rawCampaignId) && rawCampaignId > 0 ? rawCampaignId : null;
    const sessionId =
      Number.isInteger(rawSessionId) && rawSessionId > 0 ? rawSessionId : null;

    this.campaignId.set(campaignId);
    this.sessionId.set(sessionId);

    if (!campaignId) {
      this.navigationLoading.set(false);
      this.breadcrumbs.set([{ label: snapshot.routeConfig?.path === 'custom-content'
        ? 'Contenu personnalisé' : 'Campagnes' }]);
      return;
    }

    this.navigationLoading.set(true);

    if (!sessionId) {
      this.navigationRequest = this.campaignRegistry
        .getCampaign(campaignId)
        .pipe(finalize(() => this.navigationLoading.set(false)))
        .subscribe({
          next: context => {
            const isCharactersPage = this.router.url.includes('/characters');
            const isNewCharacterPage = this.router.url.endsWith('/characters/new');

            this.breadcrumbs.set([
              { label: 'Campagnes', url: '/campaigns' },
              {
                label: context.campaign.name,
                url: isCharactersPage ? `/campaigns/${campaignId}` : undefined,
              },
              ...(isCharactersPage
                ? [
                    {
                      label: 'Personnages',
                      url: isNewCharacterPage
                        ? `/campaigns/${campaignId}/characters`
                        : undefined,
                    },
                  ]
                : []),
              ...(isNewCharacterPage ? [{ label: 'Création' }] : []),
            ]);
          },
          error: () => this.setFallbackBreadcrumbs(campaignId),
        });

      return;
    }

    this.navigationRequest = forkJoin({
      campaignContext: this.campaignRegistry.getCampaign(campaignId),
      session: this.gameSessionApi.get(sessionId),
    })
      .pipe(finalize(() => this.navigationLoading.set(false)))
      .subscribe({
        next: ({ campaignContext, session }) =>
          this.breadcrumbs.set([
            { label: 'Campagnes', url: '/campaigns' },
            {
              label: campaignContext.campaign.name,
              url: `/campaigns/${campaignId}`,
            },
            { label: session.name },
            { label: 'Contrôle' },
          ]),
        error: () => this.setFallbackBreadcrumbs(campaignId),
      });
  }

  private setFallbackBreadcrumbs(campaignId: number): void {
    this.navigationLoading.set(false);
    this.breadcrumbs.set([
      { label: 'Campagnes', url: '/campaigns' },
      { label: 'Sessions', url: `/campaigns/${campaignId}` },
      ...(this.sessionId() ? [{ label: 'Contrôle' }] : []),
    ]);
  }

  private getDeepestChildRoute(): ActivatedRoute {
    let currentRoute = this.route;

    while (currentRoute.firstChild) {
      currentRoute = currentRoute.firstChild;
    }

    return currentRoute;
  }
}

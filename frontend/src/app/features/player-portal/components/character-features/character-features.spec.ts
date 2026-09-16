import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { describe, it, expect } from 'vitest';
import { PlayerPortal } from '../../player-portal';
import { CharacterStateService } from '@core/services/character-state.service';
import { CharacterSessionStateApiService } from '@core/services/character-session-state-api.service';
import { RestRequestApiService } from '@core/services/rest-request-api.service';
import { CampaignConfigurationRegistryService } from '@core/services/campaign-configuration-registry.service';

describe('Player feature consultation', () => {
  it('renders resolved features and origins safely in the middle tab, without actions', async () => {
    TestBed.configureTestingModule({ providers: [
      { provide: ActivatedRoute, useValue: { snapshot: { paramMap: { get: () => null } } } },
      { provide: CharacterStateService, useValue: { character: signal({ name: 'Test', resources: [] }) } },
      { provide: CharacterSessionStateApiService, useValue: {} },
      { provide: RestRequestApiService, useValue: {} },
      { provide: CampaignConfigurationRegistryService, useValue: {} },
    ] });
    const fixture = TestBed.createComponent(PlayerPortal);
    const portal = fixture.componentInstance as any;
    portal.loadError.set(null);
    portal.character.set({ name: 'Test' });
    portal.sessionStatus.set('live');
    portal.selectTab('features');
    portal.features.set([
      { slug: 'class-feature', name: 'Capacité en BDD', sourceName: 'Ensorceleur', sourceType: 'class', unlockLevel: 2, description: 'Première ligne\n<script>texte</script>' },
      { slug: 'progression-feature', name: 'Progression acquise', sourceName: 'Corruption', sourceType: 'progression', unlockLevel: 1, description: null },
    ]);
    await fixture.whenStable();
    const element: HTMLElement = fixture.nativeElement;
    expect(Array.from(element.querySelectorAll('.portal-tabs strong')).map(node => node.textContent?.trim())).toEqual(['État', 'Capacités', 'Possessions']);
    expect(element.querySelectorAll('.character-feature')).toHaveLength(2);
    expect(element.querySelector('.character-feature__origin')!.textContent).toContain('Ensorceleur — niveau 2');
    expect(element.querySelectorAll('.character-feature__origin')[1].textContent?.trim()).toBe('Corruption');
    expect(element.querySelector('.character-feature__description')!.textContent).toBe('Première ligne\n<script>texte</script>');
    expect(element.querySelector('.character-features script')).toBeNull();
    expect(element.querySelector('.character-features button')).toBeNull();
    expect(element.textContent).toContain('Aucune description renseignée.');
    portal.features.set([]);
    await fixture.whenStable();
    expect(element.textContent).toContain('Aucune capacité acquise');
    expect(element.querySelectorAll('.character-feature')).toHaveLength(0);
  });
});

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute } from '@angular/router';
import { describe, expect, it } from 'vitest';
import { FeatureManager } from './feature-manager';
import { AuthService } from '@core/services/auth.service';

describe('Unified reference navigation', () => {
  for (const [context, scope, admin] of [['custom', 'custom', false], ['official', 'official', true], ['official', 'custom', false]] as const) {
    it(`loads grouped tabs in ${context} with admin=${admin} through ${scope}`, async () => {
      TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(),
        { provide: AuthService, useValue: { user: () => ({ roles: admin ? ['ROLE_REFERENCE_ADMIN'] : ['ROLE_USER'] }) } },
        { provide: ActivatedRoute, useValue: { snapshot: { data: { referenceScope: context } } } }] });
      const http = TestBed.inject(HttpTestingController);
      const fixture = TestBed.createComponent(FeatureManager);
      const responses = { classes: [], subclasses: [], feats: [], races: [], abilities: [], hitDice: [], spellcastingProgressions: [],
        progressions: [], features: [], resources: [], rules: [], activationTypes: [], rechargeTypes: [], maximumTypes: [],
        campaigns: [], beasts: [], links: [], backgrounds: [], proficiencies: [], languages: [], spells: [], schools: [], total: 0, page: 1, pageSize: 25 };
      const seen = new Set<string>();
      async function flush() {
        fixture.detectChanges();
        for (const request of http.match(() => true)) {
          expect(request.request.method).toBe('GET');
          if (request.request.url !== '/api/campaigns' && !request.request.url.startsWith('/api/dnd/beast-features')) expect(request.request.url).toMatch(new RegExp(`^/api/reference/${scope}/`));
          seen.add(request.request.url);
          request.flush(request.request.url.endsWith('/classes/editor') ? { ...responses, classes: [
            { id: 10, name: 'Classe officielle test', slug: 'official-test', custom: false, origin: 'OFFICIAL', hitDie: 8, subclassSelectionLevel: 3, spellcastingProgression: 'none', editable: admin },
            { id: 11, name: 'Classe personnelle test', slug: 'custom-test', custom: true, origin: 'CUSTOM', hitDie: 8, subclassSelectionLevel: 3, spellcastingProgression: 'none', editable: true },
          ] } : responses);
        }
        await fixture.whenStable(); fixture.detectChanges();
      }
      await flush();
      const tabs = Array.from((fixture.nativeElement as HTMLElement).querySelectorAll<HTMLButtonElement>('.manager-tabs button'));
      expect(tabs).toHaveLength(context === 'official' ? 12 : 11);
      expect(fixture.nativeElement.querySelectorAll('.manager-tabs__assignments button')).toHaveLength(1);
      expect(fixture.nativeElement.querySelectorAll('.manager-tabs__catalogue button')).toHaveLength(context === 'official' ? 11 : 10);
      for (const tab of tabs) {
        if (['Équipement','Bêtes'].includes(tab.querySelector('strong')?.textContent ?? '')) continue;
        tab.click(); await flush();
        if (tab.querySelector('strong')?.textContent === 'Classes') {
          const list = (fixture.nativeElement as HTMLElement).querySelector('.class-list')!;
          expect(list.textContent).toContain(context === 'custom' ? 'Classe personnelle test' : 'Classe officielle test');
          expect(list.textContent).not.toContain(context === 'custom' ? 'Classe officielle test' : 'Classe personnelle test');
          if (context === 'official' && !admin) expect((fixture.nativeElement as HTMLElement).querySelector('fieldset')?.disabled).toBe(true);
        }
      }
      for (const category of ['classes', 'subclasses', 'races', 'feats', 'progressions', 'features', 'resources', 'feature-rules']) {
        expect(seen.has(`/api/reference/${scope}/${category}/editor`)).toBe(true);
      }
      expect(seen.has(`/api/reference/${scope}/backgrounds`)).toBe(true);
      expect(seen.has(`/api/reference/${scope}/spells`)).toBe(true);
      http.verify(); fixture.destroy();
    });
  }
});

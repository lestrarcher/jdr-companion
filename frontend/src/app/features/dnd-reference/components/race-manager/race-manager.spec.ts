import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { afterEach, describe, expect, it } from 'vitest';
import { RaceManager } from './race-manager';
import { REFERENCE_EDITOR_SCOPE } from '@core/services/reference-editor-context';
import type { RaceReference } from '@core/services/legacy-dnd-reference-api.service';

describe('Race editor scopes and contracts', () => {
  let http: HttpTestingController;
  const race: RaceReference = { id: 3, slug: 'fixture', name: 'Fixture', selectable: true, featChoiceCount: 0, abilityModifiers: [], origin: 'CUSTOM', editable: true,
    metadata: { sizeOptions: ['medium'], walkingSpeed: 9, movementSpeeds: { swim: 6 }, languages: ['common'], languageChoiceCount: null, senses: { darkvision: 18 }, damageResistances: ['fire'], damageImmunities: [], conditionImmunities: null } };
  function setup(scope: 'custom' | 'official' = 'custom') {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), { provide: REFERENCE_EDITOR_SCOPE, useValue: scope }] });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(RaceManager);
    fixture.detectChanges();
    http.expectOne(`/api/reference/${scope}/races/editor`).flush({ races: [scope === 'official' ? { ...race, origin: 'OFFICIAL' } : race], abilities: [] });
    fixture.detectChanges();
    return fixture;
  }
  afterEach(() => http.verify());
  it('preserves local metadata and never sends mutable identities on PATCH', async () => {
    const fixture = setup();
    const root: HTMLElement = fixture.nativeElement;
    root.querySelector<HTMLButtonElement>('.race-card')!.click(); fixture.detectChanges(); await fixture.whenStable();
    expect(root.querySelector<HTMLInputElement>('[name="slug"]')!.disabled).toBe(true);
    root.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    const request = http.expectOne('/api/reference/custom/races/editor/3');
    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toMatchObject(race.metadata!);
    expect(request.request.body).not.toHaveProperty('slug');
    expect(request.request.body).not.toHaveProperty('parentRaceId');
    expect(request.request.body).not.toHaveProperty('custom');
    request.flush({ message: 'Conflit' }, { status: 409, statusText: 'Conflict' }); fixture.detectChanges();
    expect(root.textContent).toContain('Conflit');
    expect(root.querySelector<HTMLInputElement>('[name="walkingSpeed"]')!.value).toBe('9');
  });
  it('uses the official endpoint only in the official context', () => {
    const fixture = setup('official');
    const root: HTMLElement = fixture.nativeElement;
    root.querySelector<HTMLButtonElement>('.race-card')!.click(); fixture.detectChanges();
    expect(root.querySelector('fieldset')!.disabled).toBe(false);
    root.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    const request = http.expectOne('/api/reference/official/races/editor/3');
    request.flush({ race: { ...race, origin: 'OFFICIAL' } });
  });
  it('prevents official mutations from the personal component', () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(RaceManager); fixture.detectChanges();
    http.expectOne('/api/reference/custom/races/editor').flush({ races: [{ ...race, origin: 'OFFICIAL', editable: false }], abilities: [] }); fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    root.querySelector<HTMLButtonElement>('.race-card')!.click(); fixture.detectChanges();
    expect(root.querySelector('fieldset')!.disabled).toBe(true);
    root.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    http.expectNone(request => request.method !== 'GET');
  });
});

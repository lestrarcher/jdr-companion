import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import { REFERENCE_VIEW_ORIGIN } from '@core/services/reference-editor-context';
import { SubclassManager } from './subclass-manager';

describe('Clerc des Brumes scenario', () => {
  it('searches Clerc OFFICIAL by name, creates CUSTOM and restores the parent after reload', async () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), { provide: REFERENCE_VIEW_ORIGIN, useValue: 'CUSTOM' }] });
    const http = TestBed.inject(HttpTestingController);
    const parent = { id: 42, slug: 'cleric', name: 'Clerc', custom: false, origin: 'OFFICIAL', hitDie: 8, subclassSelectionLevel: 1, spellcastingProgression: 'full', editable: false };
    const saved = { id: 91, slug: 'clerc-des-brumes', name: 'Clerc des Brumes', description: '', classId: 42, characterClassId: 42, spellcastingProgression: null, custom: true, origin: 'CUSTOM', editable: true };
    let fixture = TestBed.createComponent(SubclassManager);
    function flush(subclasses: object[]) {
      http.expectOne('/api/reference/custom/classes/editor').flush({ classes: [parent], spellcastingProgressions: [] });
      http.expectOne('/api/reference/custom/subclasses/editor').flush({ subclasses, spellcastingProgressions: [] });
    }
    async function settle() { fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges(); }
    flush([]); await settle();
    const root = (): HTMLElement => fixture.nativeElement;
    const search = root().querySelector<HTMLInputElement>('app-reference-picker input')!;
    search.value = 'Clerc'; search.dispatchEvent(new Event('input')); await settle();
    const select = root().querySelector<HTMLSelectElement>('app-reference-picker select')!;
    expect(select.options[1].textContent).toBe('Clerc — OFFICIAL');
    select.value = '42'; select.dispatchEvent(new Event('change')); await settle();
    for (const [name, value] of Object.entries({ name: 'Clerc des Brumes' })) {
      const input = root().querySelector<HTMLInputElement>(`input[name="${name}"]`)!;
      input.value = value; input.dispatchEvent(new Event('input')); await settle();
    }
    root().querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    const request = http.expectOne('/api/reference/custom/subclasses/editor');
    expect(request.request.method).toBe('POST');
    expect(request.request.body.characterClassId).toBe(42);
    expect(request.request.body).not.toHaveProperty('origin');
    request.flush({ item: saved }); await settle();
    expect(root().querySelector('.subclass-list')?.textContent).toContain('Clerc des Brumes — CUSTOM');
    fixture.destroy(); fixture = TestBed.createComponent(SubclassManager); flush([saved]); await settle();
    root().querySelector<HTMLButtonElement>('.subclass-card')!.click(); await settle();
    const restored = root().querySelector<HTMLSelectElement>('app-reference-picker select')!;
    expect(restored.selectedOptions[0].textContent).toBe('Clerc — OFFICIAL');
    expect(restored.disabled).toBe(true);
    expect(root().querySelector('input[type="number"][name="classId"]')).toBeNull();
    http.verify();
  });
});

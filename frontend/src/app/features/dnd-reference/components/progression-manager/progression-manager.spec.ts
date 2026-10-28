import { TestBed } from '@angular/core/testing';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { of } from 'rxjs';
import { ProgressionManager } from './progression-manager';
import { ReferenceCatalogueEditorApiService } from '@core/services/reference-catalogue-editor-api.service';
import { REFERENCE_READ_ONLY, REFERENCE_VIEW_ORIGIN } from '@core/services/reference-editor-context';
import type { ProgressionReference } from '@core/services/legacy-dnd-reference-api.service';

afterEach(() => TestBed.resetTestingModule());
describe('Progression casting default', () => {
  it('loads and saves a default ability, including explicit clearing', async () => {
    const progression = { id: 1, slug: 'shadow', name: 'Shadow', description: null,
      minimumValue: 0, maximumValue: null, accentColor: null, gainLabel: null, spendLabel: null,
      custom: true, origin: 'CUSTOM', editable: true, bulkAdjustmentEnabled: false,
      stages: [], adjustmentRules: [], defaultCastingAbility: 'wisdom' } as ProgressionReference;
    const api = { getProgressions: () => of({ progressions: [progression] }),
      updateProgression: vi.fn((_id, payload) => of({ ...progression, ...payload })) };
    TestBed.configureTestingModule({ imports: [ProgressionManager], providers: [
      { provide: REFERENCE_READ_ONLY, useValue: false },
      { provide: REFERENCE_VIEW_ORIGIN, useValue: 'CUSTOM' },
    ] });
    TestBed.overrideComponent(ProgressionManager, { set: { providers: [
      { provide: ReferenceCatalogueEditorApiService, useValue: api },
    ] } });
    const fixture = TestBed.createComponent(ProgressionManager);
    const component = fixture.componentInstance;
    component.selectProgression(progression);
    fixture.detectChanges();
    await fixture.whenStable();
    const select: HTMLSelectElement = fixture.nativeElement.querySelector('[name="defaultCastingAbility"]');
    expect(select.value).toBe('wisdom');
    select.value = 'intelligence'; select.dispatchEvent(new Event('change'));
    const irreversible: HTMLInputElement = fixture.nativeElement.querySelector('[name="irreversible"]');
    expect(irreversible.checked).toBe(false);
    irreversible.click();
    component.save();
    expect(api.updateProgression).toHaveBeenLastCalledWith(1, expect.objectContaining({ defaultCastingAbility: 'intelligence', irreversible: true }));
    component.form.defaultCastingAbility = null;
    component.save();
    expect(api.updateProgression).toHaveBeenLastCalledWith(1, expect.objectContaining({ defaultCastingAbility: null }));
  });
});

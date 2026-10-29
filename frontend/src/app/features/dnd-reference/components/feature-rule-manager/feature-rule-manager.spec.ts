import { TestBed } from '@angular/core/testing';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { of } from 'rxjs';
import { FeatureRuleManager } from './feature-rule-manager';
import { ReferenceFeatureEditorApiService } from '@core/services/reference-feature-editor-api.service';
import { ReferenceCatalogueEditorApiService } from '@core/services/reference-catalogue-editor-api.service';
import { REFERENCE_READ_ONLY, REFERENCE_VIEW_ORIGIN } from '@core/services/reference-editor-context';

afterEach(() => TestBed.resetTestingModule());
describe('Editing existing feature attributions', () => {
  it('allows a source move and a threshold without a matching stage', async () => {
    const rule = { id: 1, editable: true, origin: 'CUSTOM', feature: { id: 5, name: 'Gift' }, sourceType: 'progression', sourceId: 2, sourceName: 'Shadow', progressionThreshold: 15, unlockLevel: null, displayOrder: 0 };
    const api = { getFeatures: () => of({ features: [] }), getRules: () => of({ rules: [rule] }), updateRule: vi.fn(() => of({ rule })) };
    const reference = { getReference: () => of({ classes: [], races: [], feats: [] }), getSubclasses: () => of({ subclasses: [] }), getProgressions: () => of({ progressions: [] }) };
    TestBed.configureTestingModule({ imports: [FeatureRuleManager], providers: [
      { provide: REFERENCE_READ_ONLY, useValue: false }, { provide: REFERENCE_VIEW_ORIGIN, useValue: 'CUSTOM' },
    ] });
    TestBed.overrideComponent(FeatureRuleManager, { set: { providers: [
      { provide: ReferenceFeatureEditorApiService, useValue: api },
      { provide: ReferenceCatalogueEditorApiService, useValue: reference },
    ] } });
    const fixture = TestBed.createComponent(FeatureRuleManager);
    const component = fixture.componentInstance as any;
    component.selectRule(rule);
    component.form.sourceId = 3; component.form.progressionThreshold = 20;
    fixture.detectChanges(); await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('[name="sourceType"]').disabled).toBe(false);
    expect(fixture.nativeElement.querySelector('[name="progressionThreshold"]').disabled).toBe(false);
    component.save();
    expect(api.updateRule).toHaveBeenCalledWith(1, { featureDefinitionId: 5, sourceType: 'progression', sourceId: 3, progressionThreshold: 20, unlockLevel: null, displayOrder: 0 });
  });
});

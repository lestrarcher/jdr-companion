import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
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
      provideHttpClient(), provideHttpClientTesting(),
      { provide: REFERENCE_READ_ONLY, useValue: false }, { provide: REFERENCE_VIEW_ORIGIN, useValue: 'CUSTOM' },
    ] });
    TestBed.overrideComponent(FeatureRuleManager, { set: { providers: [
      { provide: ReferenceFeatureEditorApiService, useValue: api },
      { provide: ReferenceCatalogueEditorApiService, useValue: reference },
    ] } });
    const fixture = TestBed.createComponent(FeatureRuleManager);
    TestBed.inject(HttpTestingController).expectOne('/api/dnd/beast-features/scope/custom').flush({beasts:[],features:[],links:[]});
    const component = fixture.componentInstance as any;
    component.selectRule(rule);
    component.form.sourceId = 3; component.form.progressionThreshold = 20;
    fixture.detectChanges(); await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('[name="sourceType"]').disabled).toBe(false);
    expect(fixture.nativeElement.querySelector('[name="progressionThreshold"]').disabled).toBe(false);
    component.save();
    expect(api.updateRule).toHaveBeenCalledWith(1, { featureDefinitionId: 5, sourceType: 'progression', sourceId: 3, progressionThreshold: 20, unlockLevel: null, displayOrder: 0 });
  });
  it('assigns, deduplicates and removes beast features while preserving existing links', async () => {
    const features = [{id:7,name:'Odorat',slug:'odorat'}, {id:8,name:'Tactique',slug:'tactique'}];
    TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting()]});
    TestBed.overrideComponent(FeatureRuleManager, {set:{providers:[
      {provide:ReferenceFeatureEditorApiService,useValue:{getFeatures:()=>of({features}),getRules:()=>of({rules:[]})}},
      {provide:ReferenceCatalogueEditorApiService,useValue:{getReference:()=>of({classes:[],races:[],feats:[]}),getSubclasses:()=>of({subclasses:[]}),getProgressions:()=>of({progressions:[]})}},
    ]}});
    const fixture = TestBed.createComponent(FeatureRuleManager);
    const http = TestBed.inject(HttpTestingController);
    http.expectOne('/api/dnd/beast-features/scope/custom').flush({beasts:[{id:5,name:'Loup',slug:'loup'},{id:6,name:'Ours',slug:'ours'}],features,
      links:[{beastId:5,featureId:7},{beastId:6,featureId:7}]});
    fixture.detectChanges(); await fixture.whenStable();
    const component = fixture.componentInstance as any;
    for (let attempt=0; attempt<2; attempt++) {
      component.startCreation(); fixture.detectChanges(); await fixture.whenStable();
      const type = fixture.nativeElement.querySelector('[name=sourceType]');
      type.value='beast'; type.dispatchEvent(new Event('change')); fixture.detectChanges(); await fixture.whenStable();
      const source = fixture.nativeElement.querySelector('app-reference-picker[name=sourceId] select');
      source.value='5'; source.dispatchEvent(new Event('change'));
      const search = fixture.nativeElement.querySelector('app-reference-picker[name=featureDefinitionId] input');
      search.value='Tactique'; search.dispatchEvent(new Event('input')); fixture.detectChanges();
      const feature = fixture.nativeElement.querySelector('app-reference-picker[name=featureDefinitionId] select');
      expect(feature.textContent).toContain('Tactique');
      expect(fixture.nativeElement.querySelector('[name=featureSearch]')).toBeNull();
      expect(fixture.nativeElement.querySelector('[name=unlockLevel]')).toBeNull();
      expect(fixture.nativeElement.querySelector('[name=displayOrder]')).toBeNull();
      feature.value='8'; feature.dispatchEvent(new Event('change')); fixture.detectChanges();
      fixture.nativeElement.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
      const request = http.expectOne('/api/dnd/beast-features/scope/custom/5');
      expect(request.request.method).toBe('PUT');
      expect(request.request.body).toEqual({featureIds:[7,8]});
      request.flush({beastId:5,featureIds:[7,8]}); fixture.detectChanges();
      expect(component.beastAssignments().filter((r:any)=>r.sourceId===5)).toHaveLength(2);
    }
    const confirm = vi.spyOn(window,'confirm').mockReturnValue(true);
    component.deleteRule(component.beastAssignments().find((r:any)=>r.sourceId===5 && r.feature.id===8));
    const request = http.expectOne('/api/dnd/beast-features/scope/custom/5');
    expect(request.request.body).toEqual({featureIds:[7]});
    request.flush({beastId:5,featureIds:[7]}); fixture.detectChanges();
    expect(component.beastLinks()).toEqual([{beastId:6,featureId:7},{beastId:5,featureId:7}]);
    confirm.mockRestore(); http.verify();
  });
});

import { TestBed } from '@angular/core/testing';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { of } from 'rxjs';
import { FeatureDefinitionManager } from './feature-definition-manager';
import { ReferenceFeatureEditorApiService } from '@core/services/reference-feature-editor-api.service';
import { REFERENCE_READ_ONLY, REFERENCE_VIEW_ORIGIN } from '@core/services/reference-editor-context';

afterEach(() => TestBed.resetTestingModule());
function setup() {
  const feature = { id: 1, name: 'Gift', slug: 'gift', origin: 'CUSTOM', editable: true, custom: true, activationType: 'passive', visible: true, resourceDefinition: null };
  const api = {
    getFeatures: () => of({ features: [feature], activationTypes: [] }), getResources: () => of({ resources: [] }),
    getFeatureSpellGrantOptions: () => of({ classes: [], spells: [{ id: 9, name: 'Spell', level: 2 }] }),
    getFeatureSpellGrants: () => of({ grants: [] }),
    addFeatureSpellGrant: vi.fn(() => of({ grants: [] })),
    configureFeatureSpellGrant: vi.fn(() => of({ grants: [] })),
  };
  TestBed.configureTestingModule({ imports: [FeatureDefinitionManager], providers: [
    { provide: REFERENCE_READ_ONLY, useValue: false }, { provide: REFERENCE_VIEW_ORIGIN, useValue: 'CUSTOM' },
  ] });
  TestBed.overrideComponent(FeatureDefinitionManager, { set: { providers: [{ provide: ReferenceFeatureEditorApiService, useValue: api }] } });
  const fixture = TestBed.createComponent(FeatureDefinitionManager);
  const component = fixture.componentInstance as any;
  component.selectedFeatureId.set(1); component.loadSpellGrants(1);
  return { fixture, component, api };
}
describe('Grant casting configuration', () => {
  it.each([[true, false], [false, true], [true, true]])('creates free=%s slot=%s with base-level default and inheritance', async (free, slot) => {
    const { fixture, component, api } = setup();
    component.startSpellGrant(); component.grantSpellId = 9;
    component.freeCasting = free; component.standardCasting = slot;
    fixture.detectChanges(); await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('[name="standardCasting"]')).not.toBeNull();
    component.addSpellGrant();
    expect(api.addFeatureSpellGrant).toHaveBeenCalledWith(1, expect.objectContaining({ spellId: 9, castingAbility: null,
      casting: { freeUses: free ? 1 : 0, rechargeType: 'long-rest', castingLevel: 2, standardSlot: slot } }));
  });
  it('preserves legacy methods when editing only the ability and sends explicit new methods when requested', () => {
    const { component, api } = setup();
    component.editSpellGrant({ id: 7, mode: 'fixed', spellId: 9, unlockLevel: 1, castingAbility: 'CHOICE', freeUses: 1, rechargeType: 'long-rest',
      castingRules: { version: 1, methods: [{ kind: 'resource', cost: 2, castingLevel: 2 }, { kind: 'standard-slot' }] } });
    component.grantCastingAbility = 'WIS'; component.addSpellGrant();
    expect(api.configureFeatureSpellGrant).toHaveBeenLastCalledWith(1, 7, { castingAbility: 'WIS' });
    component.configureCasting = true; component.freeRecharge = 'short-rest'; component.addSpellGrant();
    expect(api.configureFeatureSpellGrant).toHaveBeenLastCalledWith(1, 7, expect.objectContaining({ casting: { freeUses: 1, rechargeType: 'short-rest', castingLevel: 2, standardSlot: true } }));
  });
});

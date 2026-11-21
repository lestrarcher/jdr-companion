import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CharacterFeatureSummary } from '@core/services/character-api.service';
import { CharacterFeatures } from './character-features';

describe('Feature spell ability choices', () => {
  let http: HttpTestingController;
  const url = '/api/public/characters/token/spells';
  const feature = (sourceType: 'character' | 'progression', id = 1): CharacterFeatureSummary => ({
    id, slug: `gift-${id}`, name: `Gift ${id}`, description: 'Granted spell', activationType: 'active',
    visible: true, custom: true, sourceType, sourceId: 1, sourceName: 'Story', unlockLevel: 1, displayOrder: 0, resource: null,
  });
  const choice = (id = 1, chosenAbility: string | null = null) => ({
    grantId: id, featureName: `Gift ${id}`, spellName: `Spell ${id}`, chosenAbility,
    allowedAbilities: ['intelligence', 'wisdom', 'charisma'],
  });

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => http.verify());

  for (const source of ['character', 'progression'] as const) {
    it(`configures and modifies the ability for a ${source} gift without selecting a spell`, () => {
      const fixture = TestBed.createComponent(CharacterFeatures);
      fixture.componentRef.setInput('accessToken', 'token');
      fixture.componentRef.setInput('readonly', false);
      fixture.componentRef.setInput('features', [feature(source)]);
      const changed = vi.fn();
      fixture.componentInstance.abilityChanged.subscribe(changed);
      fixture.detectChanges();
      http.expectOne(`${url}/ability-choices`).flush({ choices: [choice()] });
      fixture.detectChanges();
      const select = fixture.nativeElement.querySelector('select') as HTMLSelectElement;
      expect(select).not.toBeNull();
      select.value = 'charisma';
      select.dispatchEvent(new Event('change'));
      const save = http.expectOne(`${url}/1/ability`);
      expect(save.request.method).toBe('PUT');
      expect(save.request.body).toEqual({ ability: 'charisma' });
      save.flush({ choices: [choice(1, 'charisma')] });
      fixture.detectChanges();
      expect(changed).toHaveBeenCalledOnce();
      expect(fixture.nativeElement.querySelector('.feature-spell-settings').textContent).toContain('Charisme');
      fixture.nativeElement.querySelector('.feature-spell-setting__button').click();
      fixture.detectChanges();
      const edit = fixture.nativeElement.querySelector('select') as HTMLSelectElement;
      edit.value = 'wisdom'; edit.dispatchEvent(new Event('change'));
      http.expectOne(`${url}/1/ability`).flush({ choices: [choice(1, 'wisdom')] });
      fixture.detectChanges();
      expect(changed).toHaveBeenCalledTimes(2);
      expect(fixture.nativeElement.querySelector('.feature-spell-settings').textContent).toContain('Sagesse');
    });
  }

  it('reloads choices when the server profile unlocks or removes a progression tier', () => {
    const fixture = TestBed.createComponent(CharacterFeatures);
    fixture.componentRef.setInput('accessToken', 'token');
    fixture.componentRef.setInput('features', []);
    fixture.detectChanges();
    http.expectOne(`${url}/ability-choices`).flush({ choices: [] });
    fixture.componentRef.setInput('features', [feature('progression', 3)]);
    fixture.detectChanges();
    http.expectOne(`${url}/ability-choices`).flush({ choices: [choice(3)] });
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.feature-spell-settings').textContent).toContain('Spell 3');
    expect(fixture.nativeElement.querySelector('select')).toBeNull(); // read-only portal
    fixture.componentRef.setInput('features', []);
    fixture.detectChanges();
    http.expectOne(`${url}/ability-choices`).flush({ choices: [] });
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.feature-spell-settings')).toBeNull();
  });
});

import { TestBed } from '@angular/core/testing';
import { of, throwError } from 'rxjs';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MagicItemApiService } from '@core/services/magic-item-api.service';
import { CharacterEquipment } from './character-equipment';

afterEach(() => TestBed.resetTestingModule());

describe('GM equipment refresh', () => {
  function setup(failed = false) {
    const api = {
      listCharacterItems: vi.fn(() => of({ ownedItems: [], attunedCount: 0, attunementLimit: 3 })),
      listCatalog: vi.fn(() => of([])),
      equipCharacterItem: vi.fn(() => failed ? throwError(() => new Error('conflict')) : of({})),
    };
    TestBed.configureTestingModule({ providers: [{ provide: MagicItemApiService, useValue: api }] });
    const fixture = TestBed.createComponent(CharacterEquipment);
    fixture.componentRef.setInput('character', { id: 7 });
    fixture.componentRef.setInput('campaignId', 2);
    fixture.detectChanges();
    const refresh = vi.fn();
    fixture.componentInstance.equipmentChanged.subscribe(refresh);
    return { fixture, api, refresh };
  }

  it.each([true, false])('requests a profile refresh after equipped=%s succeeds', equipped => {
    const { fixture, api, refresh } = setup();
    (fixture.componentInstance as any).equip(42, equipped);
    expect(api.equipCharacterItem).toHaveBeenCalledWith(7, 42, equipped);
    expect(refresh).toHaveBeenCalledOnce();
    expect(api.listCharacterItems).toHaveBeenCalledTimes(2);
    fixture.destroy();
  });

  it('does not report a successful equipment change on conflict', () => {
    const { fixture, refresh } = setup(true);
    (fixture.componentInstance as any).equip(42, true);
    expect(refresh).not.toHaveBeenCalled();
    fixture.destroy();
  });
});

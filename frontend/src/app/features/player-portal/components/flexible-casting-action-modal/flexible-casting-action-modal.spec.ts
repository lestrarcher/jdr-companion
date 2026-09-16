import { TestBed } from '@angular/core/testing';
import { DestroyRef, signal } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { of, throwError } from 'rxjs';
import { describe, it, expect, vi } from 'vitest';
import { FlexibleCastingActionModal } from './flexible-casting-action-modal';
import { CharacterSessionStateApiService } from '@core/services/character-session-state-api.service';
import { PlayerPortal } from '../../player-portal';

describe('Conversion flexible', () => {
  it('shows costs, server maxima, temporary pools and disabled choices', async () => {
    const fixture = TestBed.createComponent(FlexibleCastingActionModal);
    fixture.componentRef.setInput('resources', [
      { id: 'sorcery-points', currentValue: 3, maximumValue: 13 },
      { id: 'spell-slot-1', currentValue: 2, maximumValue: 5 },
      { id: 'spell-slot-2', currentValue: 0, maximumValue: 3 },
      { id: 'spell-slot-5', currentValue: 1, maximumValue: 1 },
      { id: 'pact-magic', currentValue: 2, maximumValue: 2 },
    ]);
    await fixture.whenStable();
    const element: HTMLElement = fixture.nativeElement;
    let choices = Array.from(element.querySelectorAll<HTMLButtonElement>('.spell-levels button'));
    expect(choices.map(button => button.textContent?.trim())).toEqual([
      'Niveau 1 — 2 PS', 'Niveau 2 — 3 PS', 'Niveau 3 — 5 PSPoints insuffisants', 'Niveau 4 — 6 PSPoints insuffisants', 'Niveau 5 — 7 PSPoints insuffisants',
    ]);
    expect(choices.map(button => button.disabled)).toEqual([false, false, true, true, true]);
    expect(element.textContent).toContain('Points de sorcellerie : 3 / 13');
    const confirmed = vi.fn();
    fixture.componentInstance.confirmed.subscribe(confirmed);
    choices[0].click();
    await fixture.whenStable();
    expect(choices[0].getAttribute('aria-pressed')).toBe('true');
    element.querySelector<HTMLButtonElement>('.primary-button')!.click();
    expect(confirmed).toHaveBeenCalledWith({ mode: 'create', level: 1 });
    element.querySelectorAll<HTMLButtonElement>('.casting-modes button')[1].click();
    await fixture.whenStable();
    choices = Array.from(element.querySelectorAll<HTMLButtonElement>('.spell-levels button'));
    expect(choices).toHaveLength(2);
    expect(choices[0].textContent).toContain('Niveau 1 — 2/5');
    expect(choices[1].textContent).toContain('Niveau 5 — 1/1');
    choices[1].click();
    await fixture.whenStable();
    element.querySelector<HTMLButtonElement>('.primary-button')!.click();
    expect(confirmed).toHaveBeenCalledWith({ mode: 'convert', level: 5 });
    fixture.componentRef.setInput('resources', [{ id: 'sorcery-points', currentValue: 12, maximumValue: 13 }, { id: 'spell-slot-5', currentValue: 1, maximumValue: 1 }]);
    fixture.componentRef.setInput('error', 'Maximum dépassé');
    await fixture.whenStable();
    expect(element.querySelector<HTMLButtonElement>('.spell-levels button')!.disabled).toBe(true);
    expect(element.querySelector<HTMLButtonElement>('.primary-button')!.disabled).toBe(true);
    expect(element.querySelector('[role="alert"]')!.textContent).toContain('Maximum dépassé');
  });

  it('posts the selected level and revision to both explicit routes', () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    const api = TestBed.inject(CharacterSessionStateApiService);
    const http = TestBed.inject(HttpTestingController);
    api.createFlexibleCastingSlot('token', 3, 12).subscribe();
    const create = http.expectOne('/api/public/characters/token/actions/flexible-casting/create-spell-slot');
    expect(create.request.method).toBe('POST');
    expect(create.request.body).toEqual({ level: 3, revision: 12 });
    create.flush({});
    api.convertFlexibleCastingSlot('token', 5, 13).subscribe();
    const convert = http.expectOne('/api/public/characters/token/actions/flexible-casting/convert-spell-slot');
    expect(convert.request.body).toEqual({ level: 5, revision: 13 });
    convert.flush({});
    http.verify();
  });

  function portal() {
    const instance = Object.create(PlayerPortal.prototype) as any;
    Object.assign(instance, {
      destroyRef: TestBed.inject(DestroyRef), accessToken: 'token', serverRevision: 12,
      flexibleCastingAction: signal({ handlerType: 'flexible-casting' }), sessionStatus: signal('live'),
      saveStatus: signal('idle'), saveError: signal(null), flexibleCastingError: signal(null), flexibleCastingSaving: signal(false),
      activeAction: signal(null), actions: signal([]), characterActions: signal(null), character: signal(null),
      characterStateService: { applyServerState: vi.fn() }, loadCharacter: vi.fn(),
      characterSessionStateApi: { createFlexibleCastingSlot: vi.fn(), convertFlexibleCastingSlot: vi.fn() },
    });
    return instance;
  }

  it('only opens an available action and handles 422/409 without local resource changes', () => {
    const instance = portal();
    instance.flexibleCastingAction.set(undefined);
    instance.openAction({ handlerType: 'flexible-casting' });
    expect(instance.activeAction()).toBeNull();
    instance.flexibleCastingAction.set({ handlerType: 'flexible-casting' });
    instance.openAction(instance.flexibleCastingAction());
    expect(instance.activeAction()).not.toBeNull();
    instance.characterSessionStateApi.createFlexibleCastingSlot.mockReturnValue(throwError(() => ({ status: 422, error: { message: 'Points insuffisants' } })));
    instance.useFlexibleCasting({ mode: 'create', level: 1 });
    expect(instance.flexibleCastingError()).toBe('Points insuffisants');
    expect(instance.activeAction()).not.toBeNull();
    expect(instance.characterStateService.applyServerState).not.toHaveBeenCalled();
    instance.characterSessionStateApi.convertFlexibleCastingSlot.mockReturnValue(throwError(() => ({ status: 409 })));
    instance.useFlexibleCasting({ mode: 'convert', level: 1 });
    expect(instance.loadCharacter).toHaveBeenCalledWith(false);
    expect(instance.activeAction()).toBeNull();
    expect(instance.characterStateService.applyServerState).not.toHaveBeenCalled();
  });

  it('adopts server currents and effective maxima and closes after success', () => {
    const instance = portal();
    const response = {
      revision: 13,
      character: { slug: 'test', name: 'Test', type: 'player', totalLevel: 3, hitPoints: { maximumValue: 10 }, classSummary: [], progressions: [], resources: [
        { slug: 'sorcery-points', name: 'PS', maximum: 13, rechargeType: 'long-rest' },
        { slug: 'spell-slot-1', name: 'Niveau 1', maximum: 5, rechargeType: 'long-rest' },
      ] },
      state: { hitPoints: { current: 10, temporary: 0 }, hitDice: [], progressions: [], resources: [{ id: 'sorcery-points', currentValue: 8 }, { id: 'spell-slot-1', currentValue: 2 }] },
    };
    instance.characterSessionStateApi.createFlexibleCastingSlot.mockReturnValue(of(response));
    instance.useFlexibleCasting({ mode: 'create', level: 1 });
    expect(instance.characterSessionStateApi.createFlexibleCastingSlot).toHaveBeenCalledWith('token', 1, 12);
    const character = instance.characterStateService.applyServerState.mock.calls[0][0];
    expect(character.resources[1]).toMatchObject({ currentValue: 2, maximumValue: 5 });
    expect(character.resources[0]).toMatchObject({ currentValue: 8, maximumValue: 13 });
    expect(instance.serverRevision).toBe(13);
    expect(instance.activeAction()).toBeNull();
    expect(instance.flexibleCastingSaving()).toBe(false);
    response.revision = 14;
    response.character.resources[1] = { slug: 'spell-slot-3', name: 'Niveau 3', maximum: 3, rechargeType: 'long-rest' };
    response.state.resources = [{ id: 'sorcery-points', currentValue: 7 }, { id: 'spell-slot-3', currentValue: 1 }];
    instance.characterSessionStateApi.convertFlexibleCastingSlot.mockReturnValue(of(response));
    instance.useFlexibleCasting({ mode: 'convert', level: 3 });
    expect(instance.characterSessionStateApi.convertFlexibleCastingSlot).toHaveBeenCalledWith('token', 3, 13);
    const converted = instance.characterStateService.applyServerState.mock.calls[1][0];
    expect(converted.resources[0]).toMatchObject({ currentValue: 7, maximumValue: 13 });
    expect(converted.resources[1]).toMatchObject({ currentValue: 1, maximumValue: 3 });
  });
});

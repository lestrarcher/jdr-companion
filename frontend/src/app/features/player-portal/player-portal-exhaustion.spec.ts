import '@angular/compiler';
import { computed, signal } from '@angular/core';
import { of, Subject } from 'rxjs';
import { describe, expect, it, vi } from 'vitest';
import { PlayerPortal } from './player-portal';

function setup(level = 0) {
  const component = Object.create(PlayerPortal.prototype) as any;
  const response = new Subject<any>();
  Object.assign(component, {
    exhaustionLevel: signal(level), exhaustionSaving: signal(false),
    saveStatus: signal('idle'), saveError: signal(null), sessionStatus: signal('live'),
    pendingRestRequest: signal(null), preparationPending: signal(false),
    serverRevision: 4, loadGeneration: 1, accessToken: 'test-token',
    http: { patch: vi.fn(() => response) },
    destroyRef: { onDestroy: () => () => {} },
    loadCharacter: vi.fn(),
  });
  component.canEdit = computed(() => component.sessionStatus() === 'live');
  return { component, response };
}

describe('Épuisement 2014', () => {
  it('enregistre un niveau via un PATCH partiel avec révision', () => {
    const { component, response } = setup(2);
    component.changeExhaustion(1);
    expect(component.http.patch).toHaveBeenCalledWith('/api/public/characters/test-token',
      { state: { exhaustionLevel: 3 }, revision: 4 });
    response.next({ revision: 5, state: { exhaustionLevel: 3 } });
    expect(component.exhaustionLevel()).toBe(3);
    expect(component.serverRevision).toBe(5);
  });
  it('refuse les modifications hors bornes et en séance fermée', () => {
    const { component } = setup(6);
    component.changeExhaustion(1);
    expect(component.http.patch).not.toHaveBeenCalled();
    component.exhaustionLevel.set(0);
    component.changeExhaustion(-1);
    expect(component.http.patch).not.toHaveBeenCalled();
    component.sessionStatus.set('closed');
    component.changeExhaustion(1);
    expect(component.http.patch).not.toHaveBeenCalled();
  });
  it('ne modifie pas la valeur affichée après une erreur serveur', () => {
    const { component, response } = setup(1);
    component.changeExhaustion(1);
    response.error({ status: 422, error: { message: 'Valeur invalide' } });
    expect(component.exhaustionLevel()).toBe(1);
    expect(component.saveError()).toBe('Valeur invalide');
  });
});

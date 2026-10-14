import { computed, signal } from '@angular/core';
import { EMPTY, Subject } from 'rxjs';
import { describe, expect, it, vi } from 'vitest';
import { PlayerPortal } from './player-portal';

function snapshot() {
  return {
    character: { slug: 'test', name: 'Test', type: 'player', totalLevel: 1,
      classSummary: [], progressions: [], resources: [], hitPoints: { maximumValue: 8 } },
    state: { inspiration: true, hitPoints: { current: 8, temporary: 0 }, hitDice: [], resources: [], progressions: [] },
  };
}

// Exercise the component's refresh boundary without starting its unrelated UI services.
function portal() {
  const response = new Subject<any>();
  const component = Object.create(PlayerPortal.prototype) as any;
  Object.assign(component, {
    character: signal({}), loading: signal(false), refreshPending: false,
    remoteSynchronizationEnabled: signal(true),
    saveStatus: signal('saved'), loadGeneration: 1, serverRevision: 4,
    sessionStatus: signal('draft'), levelUpAllowed: signal(false),
    inspiration: signal(true), inspirationSaving: signal(false),
    tinkeringNotes: signal(''),
    pendingRestRequest: signal(null), preparationPending: signal(false),
    characterStateService: { revision: signal(0) },
    characterSessionStateApi: { getByAccessToken: vi.fn(() => response) },
    destroyRef: { onDestroy: () => () => {} },
    http: { patch: vi.fn() },
    remoteSynchronization: new Subject(), sessionClosed: new Subject(), activeAction: signal(null),
  });
  component.canEdit = computed(() => component.sessionStatus() === 'live');
  component.characterStateService.applyServerState = vi.fn();
  for (const name of ['abilities', 'training', 'proficiencyBonus', 'features', 'feats', 'actions', 'characterActions']) component[name] = signal(null);
  return { component, response };
}

describe('Player inspiration and refresh ordering', () => {
  it('cancels a pending debounce across CLOSED and a quick reopen; only fresh LIVE edits are sent', () => {
    vi.useFakeTimers();
    try {
      const { component } = portal();
      component.sessionStatus.set('live');
      component.characterSessionStateApi.updateByAccessToken = vi.fn(() => EMPTY);
      component.initializeRemoteSynchronization();
      const queue = () => component.remoteSynchronization.next({state:{},revision:0,generation:component.loadGeneration});
      queue(); vi.advanceTimersByTime(100);
      component.setSessionStatus('closed');
      queue(); vi.advanceTimersByTime(500);
      component.setSessionStatus('live'); vi.advanceTimersByTime(500);
      expect(component.characterSessionStateApi.updateByAccessToken).not.toHaveBeenCalled();
      queue(); vi.advanceTimersByTime(400);
      expect(component.characterSessionStateApi.updateByAccessToken).toHaveBeenCalledTimes(1);
      component.remoteSynchronization.complete();
    } finally { vi.useRealTimers(); }
  });

  it('observes closure while saving and replaces unsaved edits with the server snapshot', () => {
    const {component,response}=portal();
    component.sessionStatus.set('live'); component.saveStatus.set('saving');
    component.refreshCharacterIfChanged();
    response.next({...snapshot(),revision:4,session:{status:'closed'},levelUpAllowed:false});
    expect(component.sessionStatus()).toBe('closed');
    expect(component.saveStatus()).toBe('idle');
    expect(component.characterStateService.applyServerState).toHaveBeenCalled();
    response.complete();
  });
  it('observes DRAFT → LIVE → CLOSED at the same state revision', () => {
    const { component, response } = portal();
    component.refreshCharacterIfChanged();
    response.next({ ...snapshot(), revision: 4, session: { status: 'live' }, levelUpAllowed: true });
    expect(component.sessionStatus()).toBe('live');
    response.complete();
    const closed = new Subject<any>();
    component.characterSessionStateApi.getByAccessToken.mockReturnValue(closed);
    component.refreshCharacterIfChanged();
    closed.next({ ...snapshot(), revision: 4, session: { status: 'closed' }, levelUpAllowed: false });
    expect(component.sessionStatus()).toBe('closed');
    expect(component.levelUpAllowed()).toBe(false);
  });

  it('ignores a GET started before a successful spend and prevents overlapping polls', () => {
    const { component, response } = portal();
    component.refreshCharacterIfChanged();
    component.refreshCharacterIfChanged();
    expect(component.characterSessionStateApi.getByAccessToken).toHaveBeenCalledTimes(1);
    component.serverRevision = 5;
    component.inspiration.set(false);
    response.next({ revision: 4, session: { status: 'live' }, state: { inspiration: true } });
    expect(component.inspiration()).toBe(false);
    expect(component.serverRevision).toBe(5);
    expect(component.sessionStatus()).toBe('draft');
  });

  it.each(['draft', 'closed'])('does not spend in %s', status => {
    const { component } = portal();
    component.sessionStatus.set(status);
    component.spendInspiration();
    expect(component.http.patch).not.toHaveBeenCalled();
  });

  it('blocks double clicks, uses the revision and adopts the successful response', () => {
    const { component } = portal();
    const spent = new Subject<any>();
    component.sessionStatus.set('live');
    component.saveError = signal(null);
    component.http.patch.mockReturnValue(spent);
    component.loadCharacter = vi.fn();
    component.spendInspiration();
    component.spendInspiration();
    expect(component.http.patch).toHaveBeenCalledTimes(1);
    expect(component.http.patch.mock.calls[0][1]).toEqual({ state: { inspiration: false }, revision: 4 });
    spent.next({ revision: 5, state: { inspiration: false } });
    spent.complete();
    expect(component.inspiration()).toBe(false);
    expect(component.inspirationSaving()).toBe(false);
    expect(component.serverRevision).toBe(5);
  });

  it('reloads on 409 without pretending the spend succeeded', () => {
    const { component } = portal();
    const spent = new Subject<any>();
    component.sessionStatus.set('live');
    component.saveError = signal(null);
    component.http.patch.mockReturnValue(spent);
    component.loadCharacter = vi.fn();
    component.spendInspiration();
    spent.error({ status: 409 });
    expect(component.loadCharacter).toHaveBeenCalledWith(false);
    expect(component.inspiration()).toBe(true);
    expect(component.saveStatus()).toBe('error');
    expect(component.inspirationSaving()).toBe(false);
  });

  it('adopts a new grant from the MJ in a closed session', () => {
    const { component, response } = portal();
    component.sessionStatus.set('closed');
    component.inspiration.set(false);
    component.characterStateService.applyServerState = vi.fn();
    for (const name of ['abilities', 'training', 'proficiencyBonus', 'features', 'actions', 'characterActions']) {
      component[name] = signal(null);
    }
    component.refreshCharacterIfChanged();
    response.next({ revision: 5, session: { status: 'closed' }, levelUpAllowed: false,
      character: { slug: 'test', name: 'Test', type: 'player', totalLevel: 1,
        classSummary: [], progressions: [], resources: [], hitPoints: { maximumValue: 8 } },
      state: { inspiration: true, hitPoints: { current: 8, temporary: 0 }, hitDice: [], resources: [], progressions: [] },
    });
    expect(component.inspiration()).toBe(true);
    expect(component.serverRevision).toBe(5);
    expect(component.sessionStatus()).toBe('closed');
  });

  it('ignores responses overtaken by local edits or another load', () => {
    for (const invalidate of [
      (c: any) => c.characterStateService.revision.set(1),
      (c: any) => c.loadGeneration++,
      (c: any) => c.saveStatus.set('saving'),
    ]) {
      const { component, response } = portal();
      component.refreshCharacterIfChanged();
      invalidate(component);
      response.next({ revision: 4, session: { status: 'live' } });
      expect(component.sessionStatus()).toBe('draft');
    }
  });
});

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { afterEach, describe, expect, it } from 'vitest';
import { CharacterSessionStateApiService } from '@core/services/character-session-state-api.service';
import { SessionCharacters } from './session-characters';

function management() {
  TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
  const component = Object.create(SessionCharacters.prototype) as any;
  const state = { id: 1, revision: 4, character: { id: 30 }, state: { inspiration: false } };
  Object.assign(component, {
    characterSessionStateApi: TestBed.inject(CharacterSessionStateApiService),
    sessionId: () => 2, sessionStates: signal([state]), changingInspirationId: signal(null),
    characterActionError: signal(null), characterActionFeedback: signal(null),
    refreshingCharacters: signal(false), destroyRef: { onDestroy: () => () => {} },
  });
  return { component, http: TestBed.inject(HttpTestingController), state };
}

describe('MJ inspiration commands and synchronization', () => {
  afterEach(() => TestBed.inject(HttpTestingController).verify());

  it('grants then withdraws through the existing API with the latest revision', () => {
    const { component, http, state } = management();
    const view = { character: { id: 30 }, sessionState: state };
    component.toggleInspiration(view);
    component.toggleInspiration(view);
    const request = http.expectOne('/api/sessions/2/characters/30/inspiration');
    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({ inspiration: true, revision: 4 });
    expect(component.changingInspirationId()).toBe(30);
    request.flush({ ...state, revision: 5, state: { inspiration: true } });
    expect(component.sessionStates()[0].state.inspiration).toBe(true);
    expect(component.changingInspirationId()).toBe(null);
    component.toggleInspiration({ ...view, sessionState: component.sessionStates()[0] });
    const withdrawal = http.expectOne('/api/sessions/2/characters/30/inspiration');
    expect(withdrawal.request.body).toEqual({ inspiration: false, revision: 5 });
    withdrawal.flush({ ...state, revision: 6 });
    expect(component.sessionStates()[0].state.inspiration).toBe(false);
  });

  it('retains a successful command when an older list arrives later', () => {
    const { component, http, state } = management();
    component.refreshCharacters(true);
    const oldList = http.expectOne('/api/sessions/2/characters');
    component.toggleInspiration({ character: { id: 30 }, sessionState: state });
    http.expectOne('/api/sessions/2/characters/30/inspiration')
      .flush({ ...state, revision: 5, state: { inspiration: true } });
    oldList.flush({ states: [state] });
    expect(component.sessionStates()[0].revision).toBe(5);
    expect(component.sessionStates()[0].state.inspiration).toBe(true);
  });

  it('refreshes on conflict without retrying a stale command', () => {
    const { component, http, state } = management();
    component.toggleInspiration({ character: { id: 30 }, sessionState: state });
    http.expectOne('/api/sessions/2/characters/30/inspiration')
      .flush({}, { status: 409, statusText: 'Conflict' });
    http.expectOne('/api/sessions/2/characters').flush({ states: [{ ...state, revision: 5 }] });
    expect(component.characterActionError()).toContain('Modification concurrente');
    expect(component.changingInspirationId()).toBe(null);
    expect(component.sessionStates()[0].revision).toBe(5);
  });

  it('displays a failed command without changing the inspiration locally', () => {
    const { component, http, state } = management();
    component.toggleInspiration({ character: { id: 30 }, sessionState: state });
    http.expectOne('/api/sessions/2/characters/30/inspiration')
      .flush({ message: 'Accès refusé.' }, { status: 403, statusText: 'Forbidden' });
    expect(component.characterActionError()).toBe('Accès refusé.');
    expect(component.sessionStates()[0]).toEqual(state);
    expect(component.changingInspirationId()).toBe(null);
  });
});

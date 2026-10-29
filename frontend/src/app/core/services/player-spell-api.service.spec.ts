import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { afterEach, beforeEach, describe, expect, expectTypeOf, it, vi } from 'vitest';
import type { Observable } from 'rxjs';
import { PlayerSpellApiService } from './player-spell-api.service';
import { createSpellCastCommand, type SpellCastCommand, type SpellCastResponse } from '../models/spell-cast.model';
import type { CharacterSessionStateApiResponse } from './character-session-state-api.service';

const id = '7a00a010-0000-4000-8000-000000000001';
const intent = { revision: 42, sourceKey: 'acquisition:123', method: 'standard-slot', castingLevel: 3 } as const;
const command: SpellCastCommand = { ...intent, requestId: id };
const response: SpellCastResponse = {
  id: 1, revision: 43, campaign: { id: 2, configurationKey: 'strahd' },
  session: { id: 3, name: 'Session', status: 'live' },
  character: {
    id: 1, campaignId: 2, slug: 'fixture', name: 'Fixture', playerName: null, type: 'player', race: null,
    totalLevel: 5, proficiencyBonus: 3, classLevels: [], progressions: [], definition: {},
    hitPoints: { maximumValue: 20, baseValue: 20, constitutionModifier: 0, constitutionBonus: 0, complete: true, missingLevelPositions: [] },
    classSummary: [], abilities: [], feats: [], features: [], resources: [], actions: [],
  },
  participating: true, levelUpAllowed: false, spells: [], activeEffects: [], updatedAt: '2026-10-25T12:00:00Z',
  state: { hitPoints: { current: 20, temporary: 0 }, hitDice: [], progressions: [], resources: [{ id: 'spell-slot-3', currentValue: 1 }] },
  cast: { source: { key: 'acquisition:123', spellId: 7, policyId: 8, sourceType: 'class' }, method: 'standard-slot', castingLevel: 3, poolSlug: 'spell-slot-3', cost: 1 },
  replayed: false,
};

describe('Player spell casting API', () => {
  let api: PlayerSpellApiService;
  let http: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(PlayerSpellApiService);
    http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => { http.verify(); vi.restoreAllMocks(); TestBed.resetTestingModule(); });

  it('POSTs exactly five fields, preserving the supplied UUID and encoding the token', () => {
    const uuid = vi.spyOn(crypto, 'randomUUID');
    const input = { ...command, requestId: id.toUpperCase(), cost: 99, resources: ['untrusted'] };
    api.cast('a/b', input).subscribe();
    const req = http.expectOne('/api/public/characters/a%2Fb/spells/cast');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ ...command, requestId: id.toUpperCase() });
    expect(uuid).not.toHaveBeenCalled();
    req.flush(response);
  });

  it('returns the complete typed server response without modifying local resources', () => {
    const local = structuredClone(response);
    local.state.resources[0].currentValue = 2;
    const before = structuredClone(local);
    let received: SpellCastResponse | undefined;
    const result = api.cast('token', command);
    expectTypeOf(result).toEqualTypeOf<Observable<SpellCastResponse>>();
    expectTypeOf<SpellCastResponse>().toExtend<CharacterSessionStateApiResponse>();
    result.subscribe(value => received = value);
    expect(local).toEqual(before);
    http.expectOne('/api/public/characters/token/spells/cast').flush(response);
    expect(received).toEqual(response);
    expect(received?.revision).toBe(43);
    expect(received?.state.resources[0].currentValue).toBe(1);
    expect(local).toEqual(before);
    http.expectNone(r => r.method === 'PATCH');
  });

  it.each([400, 404, 409, 422])('preserves HTTP %s and its message without retrying', status => {
    let received: HttpErrorResponse | undefined;
    api.cast('token', command).subscribe({ error: error => received = error });
    http.expectOne('/api/public/characters/token/spells/cast').flush({ message: 'refused' }, { status, statusText: 'Rejected' });
    expect(received).toBeInstanceOf(HttpErrorResponse);
    expect(received?.status).toBe(status);
    expect(received?.error).toEqual({ message: 'refused' });
    http.expectNone('/api/public/characters/token/spells/cast');
    expect(command.revision).toBe(42);
  });

  it('retains the immutable pending command after an ambiguous network error and manually replays it', () => {
    const uuid = vi.spyOn(crypto, 'randomUUID').mockReturnValue(id);
    const mutableIntent = { ...intent };
    const pending = createSpellCastCommand(mutableIntent);
    expect(Object.isFrozen(pending)).toBe(true);
    let failure: HttpErrorResponse | undefined;
    api.cast('token', pending).subscribe({ error: error => failure = error });
    http.expectOne('/api/public/characters/token/spells/cast').error(new ProgressEvent('error'));
    expect(failure?.status).toBe(0);
    http.expectNone('/api/public/characters/token/spells/cast');
    // A later revision elsewhere must not replace this intention's revision.
    Object.assign(mutableIntent, { revision: 99 });
    let adopted: CharacterSessionStateApiResponse | undefined;
    api.cast('token', pending).subscribe(value => adopted = value);
    const retry = http.expectOne('/api/public/characters/token/spells/cast');
    expect(retry.request.body).toEqual(command);
    const replay = { ...response, revision: 100, replayed: true };
    retry.flush(replay);
    expect(adopted).toEqual(replay);
    expect(uuid).toHaveBeenCalledTimes(1);
    expect(pending).toEqual(command);
  });

  it('creates a different UUID only when a new intention is explicitly created', () => {
    const uuid = vi.spyOn(crypto, 'randomUUID').mockReturnValueOnce(id)
      .mockReturnValueOnce('7a00a010-0000-4000-8000-000000000002');
    const first = createSpellCastCommand(intent);
    const second = createSpellCastCommand(intent);
    expect(first.requestId).not.toBe(second.requestId);
    expect(uuid).toHaveBeenCalledTimes(2);
  });

  it('snapshots and freezes target parameters and sends exactly the same body on retry', () => {
    const targets = [1, 2];
    const pending = createSpellCastCommand({ ...intent, targetCharacterIds: targets, hitPointRoll: 14 });
    expect(Object.isFrozen(pending.targetCharacterIds)).toBe(true);
    targets.push(3);
    api.cast('token', pending).subscribe({ error: () => {} });
    const first = http.expectOne('/api/public/characters/token/spells/cast');
    const body = first.request.body;
    expect(body).toEqual({ ...pending, targetCharacterIds: [1, 2], hitPointRoll: 14 });
    first.error(new ProgressEvent('error'));
    api.cast('token', pending).subscribe();
    const replay = http.expectOne('/api/public/characters/token/spells/cast');
    expect(replay.request.body).toEqual(body);
    replay.flush(response);
  });
});

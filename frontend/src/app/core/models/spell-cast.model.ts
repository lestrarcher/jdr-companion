import type { HttpErrorResponse } from '@angular/common/http';
import type { CharacterSessionStateApiResponse, PlayerSpell } from '../services/character-session-state-api.service';

export type SpellCastMethod = 'standard-slot' | 'pact-slot' | 'cantrip' | 'ritual';
export interface SpellCastingAvailability {
  sourceKey: string | null;
  policyId: number | null;
  status: 'available' | 'unavailable' | 'unknown';
  reason: string | null;
  methods: Array<{
    kind: SpellCastMethod | 'normal';
    castingLevel: number | null;
    poolSlug: string | null;
    cost: number;
    status: 'available' | 'unavailable' | 'unknown';
    reason: string | null;
  }>;
}

export interface SpellCastCommand {
  readonly revision: number;
  readonly requestId: string;
  readonly sourceKey: NonNullable<PlayerSpell['key']>;
  readonly method: SpellCastMethod;
  readonly castingLevel: number;
}

export type AvailableSpellCastMethod = Omit<SpellCastingAvailability['methods'][number], 'kind' | 'castingLevel'> & {
  kind: SpellCastMethod;
  castingLevel: number;
};

/** Project executable server methods; never infer a method from a resource pool. */
export function availableSpellCastMethods(spell: PlayerSpell | undefined): AvailableSpellCastMethod[] {
  if (!spell?.key || spell.castingAvailability?.sourceKey !== spell.key) return [];
  return spell.castingAvailability.methods.filter((method): method is AvailableSpellCastMethod =>
    method.status === 'available' && method.kind !== 'normal' && method.castingLevel !== null);
}

export interface SpellCastResult {
  source: {
    key: NonNullable<PlayerSpell['key']>;
    spellId: PlayerSpell['spellId'];
    policyId: NonNullable<PlayerSpell['policyId']> | null;
    sourceType: PlayerSpell['sourceType'];
  };
  method: SpellCastMethod;
  castingLevel: number;
  poolSlug: string | null;
  cost: 0 | 1;
}

export interface SpellCastResponse extends CharacterSessionStateApiResponse {
  cast: SpellCastResult;
  replayed: boolean;
}

export interface SpellCastErrorBody { message: string }
export type SpellCastRejectionStatus = 400 | 404 | 409 | 422;
export type SpellCastRejection = Omit<HttpErrorResponse, 'status' | 'error'> & {
  status: SpellCastRejectionStatus;
  error: SpellCastErrorBody;
};
// Transport failures remain HttpErrorResponse (usually status 0), not a rejection.

/**
 * Call only for a NEW intention, then retain this immutable command as pending.
 * A manual retry reuses it unchanged, including its original revision. Neither a
 * network failure nor a 409 should create a new command automatically.
 * On success, adopt the complete server response and clear the pending command.
 */
export function createSpellCastCommand(intent: Omit<SpellCastCommand, 'requestId'>): SpellCastCommand {
  return Object.freeze({
    revision: intent.revision,
    requestId: crypto.randomUUID(),
    sourceKey: intent.sourceKey,
    method: intent.method,
    castingLevel: intent.castingLevel,
  });
}

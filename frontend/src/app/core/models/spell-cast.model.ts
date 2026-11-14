import type { HttpErrorResponse } from '@angular/common/http';
import type { CharacterSessionStateApiResponse, PlayerSpell } from '../services/character-session-state-api.service';

export type SpellCastMethod = 'standard-slot' | 'pact-slot' | 'cantrip' | 'ritual' | 'resource' | 'arcanum' | 'item-charges' | 'item-limited';
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
    metamagics?: SpellMetamagic[];
  }>;
}

export interface SpellMetamagic {
  key: string;
  name: string;
  poolSlug: string;
  cost: number;
  status: 'available' | 'unavailable' | 'unknown';
  reason: string | null;
  playerChecks: string[];
}

export function metamagicReasonLabel(reason: string | null): string {
  const labels: Record<string, string> = {
    metamagic_not_acquired: 'Métamagie non acquise.',
    metamagic_self_range: 'Incompatible avec une portée personnelle.',
    metamagic_multiple_possible_targets: 'Ce sort peut cibler plusieurs créatures à ce niveau.',
    metamagic_range_too_short: 'Portée trop courte ou personnelle.',
    metamagic_duration_too_short: 'Durée du sort trop courte.',
    metamagic_requires_one_action: 'Nécessite une incantation d’une action, hors rituel.',
    metamagic_no_verbal_or_somatic_components: 'Aucune composante verbale ou somatique.',
    insufficient_sorcery_points: 'Points de sorcellerie insuffisants.',
    sorcery_points_exhausted: 'Points de sorcellerie épuisés.',
    sorcery_points_inactive_pool: 'Réserve de sorcellerie inactive.',
    sorcery_points_unavailable: 'Réserve de sorcellerie indisponible.',
    method_not_available: 'Moyen de lancement indisponible.',
  };
  return labels[reason ?? ''] ?? (reason?.startsWith('sorcery_points_')
    ? 'État des points de sorcellerie à actualiser.' : 'Indisponible pour ce lancement.');
}

export interface SpellCastCommand {
  readonly revision: number;
  readonly requestId: string;
  readonly sourceKey: NonNullable<PlayerSpell['key']>;
  readonly method: SpellCastMethod;
  readonly castingLevel: number;
  readonly targetCharacterIds?: readonly number[];
  readonly hitPointRoll?: number;
  readonly metamagic?: string;
  readonly metamagics?: readonly string[];
}

export function spellHitPointEffect(spell: PlayerSpell | undefined): 'aid' | 'feast' | null {
  switch (spell?.details?.importKey) {
    case 'aidedd-2014:aide': return 'aid';
    case 'aidedd-2014:festin-des-heros': return 'feast';
    default: return null;
  }
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
  metamagics?: Pick<SpellMetamagic, 'key' | 'poolSlug' | 'cost'>[];
  metamagic?: Pick<SpellMetamagic, 'key' | 'poolSlug' | 'cost'>;
  source: {
    key: NonNullable<PlayerSpell['key']>;
    spellId: PlayerSpell['spellId'];
    policyId: NonNullable<PlayerSpell['policyId']> | null;
    sourceType: PlayerSpell['sourceType'];
  };
  method: SpellCastMethod;
  castingLevel: number;
  poolSlug: string | null;
  cost: number;
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
    ...(intent.targetCharacterIds !== undefined ? { targetCharacterIds: Object.freeze([...intent.targetCharacterIds]) } : {}),
    ...(intent.hitPointRoll !== undefined ? { hitPointRoll: intent.hitPointRoll } : {}),
    ...(intent.metamagic !== undefined ? { metamagic: intent.metamagic } : {}),
    ...(intent.metamagics !== undefined ? { metamagics: Object.freeze([...intent.metamagics]) } : {}),
  });
}

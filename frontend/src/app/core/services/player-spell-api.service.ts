import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import type { PlayerSpell } from './character-session-state-api.service';
import type { SpellCastCommand, SpellCastResponse } from '../models/spell-cast.model';

export interface SpellCandidate { id: number; name: string; level: number; school?: string | null }
export interface PlayerSpellChoice {
  key: string; kind: 'class' | 'feature'; grantId: number | null; policyId: number | null; ruleId: number | null;
  source: string; label: string; remaining: number; ability: string | null; available: SpellCandidate[];
}
export interface PlayerSpellPreparation {
  policyId: number; source: string; ability: string; learningStyle: 'spellbook' | 'prepared';
  maximum: number; selected: number[]; available: SpellCandidate[]; blocked: boolean;
}
export interface PlayerSpellView {
  spellPreparationAllowed: boolean;
  revision: number; editable: boolean; spells: PlayerSpell[]; choices: PlayerSpellChoice[];
  preparations: PlayerSpellPreparation[]; warnings: string[];
}
@Injectable({ providedIn: 'root' })
export class PlayerSpellApiService {
  private readonly http = inject(HttpClient);
  private base(token: string): string { return `/api/public/characters/${encodeURIComponent(token)}/spells`; }
  get(token: string) { return this.http.get<PlayerSpellView>(this.base(token)); }
  cast(token: string, command: SpellCastCommand) {
    const { revision, requestId, sourceKey, method, castingLevel } = command;
    return this.http.post<SpellCastResponse>(`${this.base(token)}/cast`, {
      revision, requestId, sourceKey, method, castingLevel,
      ...(command.targetCharacterIds !== undefined ? { targetCharacterIds: [...command.targetCharacterIds] } : {}),
      ...(command.hitPointRoll !== undefined ? { hitPointRoll: command.hitPointRoll } : {}),
    });
  }
  choose(token: string, revision: number, choice: PlayerSpellChoice, spellId: number) {
    return choice.kind === 'feature'
      ? this.http.post<PlayerSpellView>(`${this.base(token)}/choose`, { revision, grantId: choice.grantId, spellId })
      : this.http.post<PlayerSpellView>(`${this.base(token)}/learn`, { revision, policyId: choice.policyId, ruleId: choice.ruleId, spellId });
  }
  confirmPreparation(token: string, revision: number, selections: { policyId: number; spellIds: number[] }[]) {
    return this.http.post<PlayerSpellView>(`${this.base(token)}/confirm-preparation`, { revision, selections });
  }
}

import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { RouterLink } from '@angular/router';
import { forkJoin, of, catchError, switchMap } from 'rxjs';
import { CharacterProfile } from '@core/services/character-api.service';

type Policy = { className: string; status: string; policyIds: number[] };
type SpellRow = { id: number; name: string; level: number; school?: string; ritual?: boolean; concentration?: boolean; is_ritual?: boolean; is_concentration?: boolean; casting_time?: string; castingTime?: string; casting_time_text?: string; castingTimeText?: string; casting_time_type?: string; castingTimeType?: string };
type Acquisition = { id: number; spell_id: number; spell_name: string; spell_level?: number; provenance?: string };
type Grant = { spellId: number; key?: string };
type Options = { acquisitions?: Acquisition[]; automatic?: Grant[]; ability?: string; status?: string };
type Entry = { id: number; name: string; level: number; origin: string; ritual: boolean; concentration: boolean; action: string | null };

@Component({
  selector: 'app-character-spells',
  standalone: true,
  imports: [RouterLink],
  templateUrl: './character-spells.html',
  styleUrl: './character-spells.scss',
})
export class CharacterSpells {
  private readonly http = inject(HttpClient);
  readonly character = input.required<CharacterProfile>();
  readonly campaignId = input.required<number>();
  readonly loading = signal(true);
  readonly error = signal('');
  readonly entries = signal<Entry[]>([]);
  readonly policies = signal<Policy[]>([]);
  readonly spellAbility = signal<string | null>(null);
  readonly spellAbilityLabel = computed(() => ({intelligence: 'INT', wisdom: 'SAG', charisma: 'CHA'}[this.spellAbility() ?? ''] ?? this.spellAbility()?.toUpperCase() ?? '—'));
  readonly castingTime = (spell: SpellRow | null): string | null => {
    const value = String(spell?.casting_time ?? spell?.castingTime ?? spell?.casting_time_text ?? spell?.castingTimeText ?? spell?.casting_time_type ?? spell?.castingTimeType ?? '').trim().toLowerCase();
    if (!value) return null;
    if (/réaction|reaction/.test(value)) return 'Réaction';
    if (/action bonus|bonus action/.test(value)) return 'Action bonus';
    if (/^(1 |une? )?action$|^action$/.test(value)) return 'Action';
    return null;
  };
  readonly selectedLevel = signal<number | null>(null);
  readonly groups = computed(() => {
    const rows = this.entries().filter(s => this.selectedLevel() === null || s.level === this.selectedLevel());
    return [...new Set(rows.map(s => s.level))].sort((a,b) => a-b).map(level => ({level, spells: rows.filter(s => s.level === level).sort((a,b) => a.name.localeCompare(b.name, 'fr'))}));
  });
  readonly levels = computed(() => [...new Set(this.entries().map(s => s.level))].sort((a,b) => a-b));
  constructor() {
    effect(onCleanup => {
      const id = this.character().id;
      this.loading.set(true); this.error.set(''); this.entries.set([]); this.spellAbility.set(null);
      const sub = this.http.get<{ policies: Policy[] }>(`/api/characters/${id}/spell-acquisitions`).pipe(
        switchMap(status => {
          this.policies.set(status.policies ?? []);
          const ids = (status.policies ?? []).filter(p => p.status === 'policy-configured').flatMap(p => p.policyIds.slice(0,1).map(policyId => ({policyId, className:p.className})));
          if (!ids.length) return of([] as {className:string; data:Options}[]);
          return forkJoin(ids.map(p => this.http.get<Options>(`/api/characters/${id}/spell-acquisitions/${p.policyId}`).pipe(
            switchMap(data => of({className:p.className, data})),
            catchError(() => of({className:p.className, data:{} as Options}))
          )));
        }),
        switchMap(options => {
          this.spellAbility.set(options.map(o => o.data.ability).find((v): v is string => typeof v === 'string' && !!v) ?? null);
          const raw = options.flatMap(({className,data}) => [
            ...(data.acquisitions ?? []).map(a => ({id:Number(a.spell_id), fallback:a.spell_name, level:Number(a.spell_level ?? 0), origin:className})),
            ...(data.automatic ?? []).map(a => ({id:Number(a.spellId), fallback:'Sort accordé', level:0, origin:`${className} · octroi automatique`}))
          ]).filter(s => Number.isFinite(s.id) && s.id > 0);
          if (!raw.length) return of([] as Entry[]);
          const unique = [...new Set(raw.map(s => s.id))];
          return forkJoin(unique.map(spellId => this.http.get<{spell:SpellRow}>(`/api/reference/custom/spells/${spellId}`).pipe(
            catchError(() => of({spell: null as unknown as SpellRow}))
          ))).pipe(switchMap(details => of(raw.map(s => {
            const spell = details[unique.indexOf(s.id)]?.spell;
            return {id:s.id, name:spell?.name ?? s.fallback, level:spell?.level ?? s.level, origin:s.origin,
              ritual:Boolean(spell?.ritual ?? spell?.is_ritual), concentration:Boolean(spell?.concentration ?? spell?.is_concentration), action:this.castingTime(spell ?? null)};
          }))));
        })
      ).subscribe({next:entries => {this.entries.set(entries);this.loading.set(false);},error:() => {this.error.set('Impossible de charger les sorts du personnage.');this.loading.set(false);}});
      onCleanup(() => sub.unsubscribe());
    });
  }
}

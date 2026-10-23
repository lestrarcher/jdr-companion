import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { RouterLink } from '@angular/router';
import { forkJoin, of, catchError, switchMap } from 'rxjs';
import { CharacterProfile } from '@core/services/character-api.service';
import { SpellInitializationPreview } from '@core/models/spell-initialization.model';
import { CharacterApiService } from '@core/services/character-api.service';

type FeatureSpellStatus = { known: { grantId: number; spellId: number; spellName: string; featureName: string; castingAbility?: string | null; spellSaveDc?: number | null; spellAttackBonus?: number | null }[]; pending: { grantId: number; featureName: string; spellLevel: number; classId: number | null; schools: string[]; castingAbility?: string | null }[] };
type FeatureSpellOption = { id: number; name: string; level: number; school?: string };
type Policy = { className: string; status: string; policyIds: number[] };
type SpellRow = { id: number; name: string; level: number; school?: string; ritual?: boolean; concentration?: boolean; is_ritual?: boolean; is_concentration?: boolean; casting_time?: string; castingTime?: string; casting_time_text?: string; castingTimeText?: string; casting_time_type?: string; castingTimeType?: string };
type Acquisition = { id: number; spell_id: number; spell_name: string; spell_level?: number; provenance?: string };
type Grant = { spellId: number; key?: string };
type Options = { acquisitions?: Acquisition[]; automatic?: Grant[]; ability?: string | null; spellSaveDc?: number | null; spellAttackBonus?: number | null; status?: string };
type CastingPolicy = { policyId: number; className: string; data: Options };
type SpellDetail = SpellRow & { range?: string | null; duration?: string | null; verbal?: boolean; somatic?: boolean; material?: boolean; materialDescription?: string | null; description?: string | null; higherLevels?: string | null };
type Entry = { id: number; name: string; level: number; origin: string; ritual: boolean; concentration: boolean; action: string | null; detail: SpellDetail | null };

@Component({
  selector: 'app-character-spells',
  standalone: true,
  imports: [RouterLink],
  templateUrl: './character-spells.html',
  styleUrl: './character-spells.scss',
})
export class CharacterSpells {
  readonly refresh = signal(0);
  readonly featureSpells = signal<FeatureSpellStatus>({ known: [], pending: [] });
  readonly featureOptions = signal<Record<number, FeatureSpellOption[]>>({});
  readonly selectedFeatureSpells = signal<Record<number, number>>({});
  readonly featureBusy = signal(false);
  readonly featureError = signal('');
  loadFeatureOptions(grantId: number): void {
    this.featureError.set('');
    this.http.get<{ spells: FeatureSpellOption[] }>(`/api/characters/${this.character().id}/feature-spells/${grantId}/options`).subscribe({
      next: result => this.featureOptions.update(options => ({ ...options, [grantId]: result.spells ?? [] })),
      error: () => this.featureError.set('Impossible de récupérer les sorts autorisés.'),
    });
  }
  selectFeatureSpell(grantId: number, value: string): void {
    this.selectedFeatureSpells.update(selected => ({ ...selected, [grantId]: Number(value) }));
  }
  chooseFeatureSpell(grantId: number): void {
    const spellId = this.selectedFeatureSpells()[grantId];
    if (!spellId || this.featureBusy()) return;
    this.featureBusy.set(true); this.featureError.set('');
    this.http.post<FeatureSpellStatus>(`/api/characters/${this.character().id}/feature-spells/${grantId}/choose`, { spellId }).subscribe({
      next: () => { this.featureBusy.set(false); this.refresh.update(value => value + 1); },
      error: () => { this.featureBusy.set(false); this.featureError.set('Impossible d’enregistrer le choix de sort.'); },
    });
  }
  synchronizeFeatureSpells(): void {
    if (this.featureBusy()) return;
    this.featureBusy.set(true); this.featureError.set('');
    this.http.post<FeatureSpellStatus>(`/api/characters/${this.character().id}/feature-spells/synchronize`, {}).subscribe({
      next: () => { this.featureBusy.set(false); this.refresh.update(value => value + 1); },
      error: () => { this.featureBusy.set(false); this.featureError.set('Impossible de synchroniser les sorts des capacités.'); },
    });
  }
  readonly initializationPending = signal(false);
  private readonly characterApi = inject(CharacterApiService);
  private readonly http = inject(HttpClient);
  readonly character = input.required<CharacterProfile>();
  readonly campaignId = input.required<number>();
  readonly spellSlotMaximums = computed(() => this.character().resources
    .flatMap(resource => {
      const match = /^spell-slot-([1-9])$/.exec(resource.slug);
      return match && resource.maximum > 0
        ? [{ level: Number(match[1]), maximum: resource.maximum }]
        : [];
    })
    .sort((a, b) => a.level - b.level));
  readonly loading = signal(true);
  readonly error = signal('');
  readonly entries = signal<Entry[]>([]);
  readonly selectedSpell = signal<Entry | null>(null);
  readonly schoolLabel = (school: string | undefined): string =>
    ({ abjuration: 'Abjuration', conjuration: 'Invocation', divination: 'Divination', enchantment: 'Enchantement', evocation: 'Évocation', illusion: 'Illusion', necromancy: 'Nécromancie', transmutation: 'Transmutation' }[school ?? ''] ?? school ?? '—');
  readonly components = (spell: SpellDetail): string => {
    const labels = [spell.verbal ? 'V' : '', spell.somatic ? 'S' : '', spell.material ? 'M' : ''].filter(Boolean);
    return labels.join(', ') || '—';
  };
  readonly policies = signal<Policy[]>([]);
  readonly castingPolicies = signal<CastingPolicy[]>([]);
  readonly abilityLabel = (ability: string | null | undefined): string =>
    ({ strength: 'Force', dexterity: 'Dextérité', constitution: 'Constitution', intelligence: 'Intelligence', wisdom: 'Sagesse', charisma: 'Charisme' }[ability ?? ''] ?? '—');
  readonly signedBonus = (bonus: number | null | undefined): string =>
    bonus == null ? '—' : `${bonus >= 0 ? '+' : ''}${bonus}`;
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
      this.refresh();
      this.initializationPending.set(false);
      if (this.character().totalLevel === 1) {
        const initializationSub = this.characterApi.getSpellInitialization(id).subscribe({
          next: (preview: SpellInitializationPreview) => this.initializationPending.set(preview.classes.some(c => c.status === 'policy-configured' && !!c.options && !c.options.blocked.length && (c.options.choices?.some(choice => choice.remaining > 0) ?? Object.values(c.options.remaining ?? {}).some(n => Number(n) > 0)))),
          error: () => this.initializationPending.set(false),
        });
        onCleanup(() => initializationSub.unsubscribe());
      }
      this.selectedSpell.set(null);
      this.loading.set(true); this.error.set(''); this.entries.set([]); this.policies.set([]); this.castingPolicies.set([]); this.featureSpells.set({ known: [], pending: [] }); this.featureOptions.set({});
      const sub = this.http.get<{ policies: Policy[] }>(`/api/characters/${id}/spell-acquisitions`).pipe(
        switchMap(status => {
          this.policies.set(status.policies ?? []);
          const ids = (status.policies ?? []).filter(p => p.status === 'policy-configured').flatMap(p => p.policyIds.map(policyId => ({policyId, className:p.className})));
          if (!ids.length) return of([] as CastingPolicy[]);
          return forkJoin(ids.map(p => this.http.get<Options>(`/api/characters/${id}/spell-acquisitions/${p.policyId}`).pipe(
            switchMap(data => of({...p, data})),
            catchError(() => of({...p, data:{} as Options}))
          )));
        }),
        switchMap(options => forkJoin({
          policies: of(options),
          features: this.http.get<FeatureSpellStatus>(`/api/characters/${id}/feature-spells`).pipe(catchError(() => of({ known: [], pending: [] } as FeatureSpellStatus))),
        })),
        switchMap(({ policies: options, features }) => {
          this.featureSpells.set(features);
          this.castingPolicies.set(options);
          const raw = [...options.flatMap(({className,data}) => [
            ...(data.acquisitions ?? []).map(a => ({id:Number(a.spell_id), fallback:a.spell_name, level:Number(a.spell_level ?? 0), origin:className})),
            ...(data.automatic ?? []).map(a => ({id:Number(a.spellId), fallback:'Sort accordé', level:0, origin:`${className} · octroi automatique`}))
          ]), ...features.known.map(s => ({ id: Number(s.spellId), fallback: s.spellName, level: 0, origin: s.featureName }))
          ].filter(s => Number.isFinite(s.id) && s.id > 0);
          if (!raw.length) return of([] as Entry[]);
          const unique = [...new Set(raw.map(s => s.id))];
          // Fetch spell details in one HTTP request instead of one per spell.
          return this.http.get<{ spells: Record<string, SpellDetail | null> }>(
            '/api/reference/custom/spells/batch-details',
            { params: { ids: unique.join(',') } }
          ).pipe(
            switchMap(({ spells }) => of(raw.map(item => {
              const spell = spells[String(item.id)] ?? null;
              return { id: item.id, name: spell?.name ?? item.fallback, level: spell?.level ?? item.level,
                origin: item.origin, detail: spell,
                ritual: Boolean(spell?.ritual ?? spell?.is_ritual),
                concentration: Boolean(spell?.concentration ?? spell?.is_concentration),
                action: this.castingTime(spell) };
            })))
          );
        })
      ).subscribe({next:entries => {this.entries.set(entries);this.loading.set(false);},error:() => {this.error.set('Impossible de charger les sorts du personnage.');this.loading.set(false);}});
      onCleanup(() => sub.unsubscribe());
    });
  }
}

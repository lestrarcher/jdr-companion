import { Component, computed, input, output, signal, inject, OnInit, OnChanges, SimpleChanges } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { CharacterAbilityScore, CharacterFeatSummary, CharacterFeatureSummary } from '@core/services/character-api.service';

interface FeatureFilter {
  key: string;
  label: string;
  count: number;
}

interface ReaderEntry {
  key: string;
  filterKey: string;
  name: string;
  description: string | null;
  sourceName: string;
  sourceType: string;
  level: number | null;
  activationType: string | null;
}

type RacialAbilityChoice = { grantId: number; featureName: string; spellName: string; chosenAbility: string | null; allowedAbilities: string[] };

@Component({
  selector: 'app-character-features',
  templateUrl: './character-features.html',
  styleUrl: './character-features.scss',
})
export class CharacterFeatures implements OnInit, OnChanges {
  private readonly http = inject(HttpClient);
  readonly accessToken = input('');
  readonly readonly = input(true);
  readonly abilityChanged = output<void>();
  protected readonly racialAbilities = signal<RacialAbilityChoice[]>([]);
  protected readonly racialAbilityBusy = signal(false);
  protected readonly racialAbilityError = signal('');
  protected readonly editingGrantId = signal<number | null>(null);
  protected readonly currentFeatureChoices = computed(() => this.racialAbilities().filter(choice => choice.featureName === this.selectedEntry()?.name));
  protected abilityLabel(value: string | null): string {
    return ({ intelligence: 'Intelligence', wisdom: 'Sagesse', charisma: 'Charisme' } as Record<string, string>)[value ?? ''] ?? 'À définir';
  }
  ngOnInit(): void { this.loadRacialAbilities(); }
  ngOnChanges(changes: SimpleChanges): void {
    if (changes['accessToken'] && !changes['accessToken'].firstChange) this.loadRacialAbilities();
  }
  private loadRacialAbilities(): void {
    if (!this.accessToken()) { this.racialAbilities.set([]); return; }
    this.http.get<{ choices: RacialAbilityChoice[] }>(`/api/public/characters/${this.accessToken()}/spells/ability-choices`).subscribe({
      next: result => this.racialAbilities.set(result.choices),
      error: () => this.racialAbilityError.set('Impossible de charger les choix de caractéristique.'),
    });
  }
  protected chooseRacialAbility(grantId: number, ability: string): void {
    if (!ability || this.readonly() || this.racialAbilityBusy()) return;
    this.racialAbilityBusy.set(true);
    this.racialAbilityError.set('');
    this.http.put<{ choices: RacialAbilityChoice[] }>(`/api/public/characters/${this.accessToken()}/spells/${grantId}/ability`, { ability }).subscribe({
      next: result => {
        this.racialAbilities.set(result.choices);
        this.racialAbilityBusy.set(false);
        this.editingGrantId.set(null);
        this.abilityChanged.emit();
      },
      error: () => {
        this.racialAbilityBusy.set(false);
        this.racialAbilityError.set('Impossible d’enregistrer la caractéristique.');
      },
    });
  }

  readonly abilities = input<CharacterAbilityScore[]>([]);
  readonly features = input.required<CharacterFeatureSummary[]>();
  readonly feats = input<CharacterFeatSummary[]>([]);

  protected readonly activeFilter = signal('all');
  protected readonly selectedKey = signal<string | null>(null);

  protected readonly filters = computed<FeatureFilter[]>(() => {
    const counts = new Map<string, { label: string; count: number }>();

    for (const feature of this.features()) {
      const key = this.featureFilterKey(feature);
      const label = feature.sourceName || this.sourceLabel(feature.sourceType);
      const current = counts.get(key);
      counts.set(key, { label, count: (current?.count ?? 0) + 1 });
    }

    if (this.feats().length > 0) {
      counts.set('feat', { label: 'Don', count: this.feats().length });
    }

    return [
      { key: 'all', label: 'Tous', count: this.features().length + this.feats().length },
      ...Array.from(counts, ([key, value]) => ({ key, ...value })),
    ];
  });

  protected readonly entries = computed<ReaderEntry[]>(() => [
    ...this.features().map(feature => ({
      key: `feature:${feature.id}:${feature.slug}`,
      name: feature.name,
      description: feature.description,
      sourceName: feature.sourceName || this.sourceLabel(feature.sourceType),
      sourceType: feature.sourceType,
      level: feature.sourceType === 'progression' || feature.unlockLevel <= 0 ? null : feature.unlockLevel,
      activationType: feature.activationType,
      filterKey: this.featureFilterKey(feature),
    })),
    ...this.feats().map(feat => ({
      key: `feat:${feat.id}`,
      name: feat.name,
      description: feat.description,
      sourceName: 'Don',
      sourceType: 'feat',
      level: feat.acquiredAtLevel,
      activationType: null,
      filterKey: 'feat',
    })),
  ]);

  protected readonly visibleEntries = computed(() => {
    const filter = this.activeFilter();
    return this.entries().filter(entry => filter === 'all' || entry.filterKey === filter);
  });

  protected readonly selectedEntry = computed(() => {
    const visible = this.visibleEntries();
    return visible.find(entry => entry.key === this.selectedKey()) ?? visible[0] ?? null;
  });

  protected selectFilter(filter: string): void {
    this.activeFilter.set(filter);
    this.selectedKey.set(null);
  }

  protected selectEntry(key: string): void {
    this.selectedKey.set(key);
  }

  protected filterSourceType(key: string): string {
    return key === 'feat' ? 'feat' : key === 'all' ? 'all' : key.split(':')[0];
  }

  protected sourceClass(sourceType: string): string {
    return `feature-tag--${sourceType}`;
  }

  private featureFilterKey(feature: CharacterFeatureSummary): string {
    return `${feature.sourceType}:${feature.sourceId}`;
  }

  private sourceLabel(sourceType: string): string {
    const labels: Record<string, string> = {
      class: 'Classe', subclass: 'Sous-classe', race: 'Race',
      ancestry: 'Ascendance', progression: 'Progression', feat: 'Don', character: 'Personnage',
    };
    return labels[sourceType] ?? sourceType;
  }
}

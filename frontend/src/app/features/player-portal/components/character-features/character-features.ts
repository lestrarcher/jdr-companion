import { Component, computed, input, signal } from '@angular/core';
import { CharacterAbilityScore, CharacterFeatSummary, CharacterFeatureSummary } from '@core/services/character-api.service';

interface FeatureFilter {
  key: string;
  label: string;
  count: number;
}

@Component({
  selector: 'app-character-features',
  templateUrl: './character-features.html',
  styleUrl: './character-features.scss',
})
export class CharacterFeatures {
  readonly abilities = input<CharacterAbilityScore[]>([]);
  readonly features = input.required<CharacterFeatureSummary[]>();
  readonly feats = input<CharacterFeatSummary[]>([]);
  protected readonly activeFilter = signal('all');

  protected readonly filters = computed<FeatureFilter[]>(() => {
    const counts = new Map<string, { label: string; count: number }>();

    for (const feature of this.features()) {
      const key = this.featureFilterKey(feature);
      const label = feature.sourceName || this.sourceLabel(feature.sourceType);
      const current = counts.get(key);
      counts.set(key, { label, count: (current?.count ?? 0) + 1 });
    }

    if (this.feats().length > 0) counts.set('feat', { label: 'Don', count: this.feats().length });

    return [
      { key: 'all', label: 'Tous', count: this.features().length + this.feats().length },
      ...Array.from(counts, ([key, value]) => ({ key, ...value })),
    ];
  });

  protected readonly filteredFeatures = computed(() => this.activeFilter() === 'all' ? this.features() : this.features().filter(feature => this.featureFilterKey(feature) === this.activeFilter()));
  protected readonly filteredFeats = computed(() => this.activeFilter() === 'all' || this.activeFilter() === 'feat' ? this.feats() : []);

  protected selectFilter(filter: string): void {
    this.activeFilter.set(filter);
  }

  protected sourceClass(sourceType: string): string {
    return `feature-tag--${sourceType}`;
  }

  private featureFilterKey(feature: CharacterFeatureSummary): string {
    return `${feature.sourceType}:${feature.sourceId}`;
  }

  private sourceLabel(sourceType: string): string {
    const labels: Record<string, string> = { class: 'Classe', subclass: 'Sous-classe', race: 'Race', ancestry: 'Ascendance', progression: 'Progression', feat: 'Don' };
    return labels[sourceType] ?? sourceType;
  }
}

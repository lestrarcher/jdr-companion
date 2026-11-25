import { Component, computed, input } from '@angular/core';
import { CharacterHitPoints } from '@core/services/character-api.service';

@Component({
  selector: 'app-hit-point-breakdown',
  template: `
    @if (calculation(); as hp) {
      <details>
        <summary>Détail des PV maximaux <strong>{{ hp.complete ? maximum() : '—' }}</strong></summary>
        <dl>
          <div><dt>Gains bruts</dt><dd>{{ hp.baseValue }}</dd></div>
          <div><dt>Constitution ({{ signed(hp.constitutionModifier) }} / niveau)</dt><dd>{{ signed(hp.constitutionBonus) }}</dd></div>
          @for (bonus of hp.featureContributions ?? []; track bonus.featureSlug + ':' + bonus.key) {
            <div><dt>{{ bonus.label }}</dt><dd>{{ signed(bonus.value) }}</dd></div>
          }
          @if (minimumCorrection() > 0) {
            <div><dt>Minimum de 1 PV par niveau</dt><dd>{{ signed(minimumCorrection()) }}</dd></div>
          }
          @if (adjustment() !== 0) {
            <div><dt>Ajustement du maximum</dt><dd>{{ signed(adjustment()) }}</dd></div>
          }
          @if (exhaustionLevel() >= 4) {
            <div><dt>Épuisement {{ exhaustionLevel() }} : maximum divisé par 2</dt><dd>÷ 2</dd></div>
          }
          @if (!hp.complete) { <div><dt>Historique incomplet</dt><dd>—</dd></div> }
        </dl>
      </details>
    }
  `,
  styles: `
    :host { display: block; min-width: 0; }
    details { padding: .65rem .8rem; border: 1px solid #ffffff1f; border-radius: .65rem; font-size: .8rem; }
    summary { cursor: pointer; color: #e3d4b2; }
    summary strong { margin-left: .4rem; font-variant-numeric: tabular-nums; }
    dl { display: grid; gap: .4rem; margin: .7rem 0 0; }
    dl div { display: flex; justify-content: space-between; gap: .75rem; }
    dt { min-width: 0; overflow-wrap: anywhere; }
    dd { margin: 0; flex-shrink: 0; font-variant-numeric: tabular-nums; }
  `,
})
export class HitPointBreakdown {
  readonly calculation = input<CharacterHitPoints | null>(null);
  readonly adjustment = input(0);
  readonly exhaustionLevel = input(0);
  readonly maximum = input<number | null>(null);
  protected readonly minimumCorrection = computed(() => {
    const hp = this.calculation();
    return hp?.maximumValue == null ? 0 : Math.max(0, hp.maximumValue - hp.baseValue - hp.constitutionBonus - (hp.featureBonus ?? 0));
  });
  protected signed(value: number): string { return value > 0 ? '+' + value : String(value); }
}

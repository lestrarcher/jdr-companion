import { ChangeDetectionStrategy, Component, computed, inject, input, output, OnInit, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { PlayerSpellApiService } from '@core/services/player-spell-api.service';
import { CharacterMagicItemInventoryResponse, PublicCharacterMagicItemApiService } from '@core/services/public-character-magic-item-api.service';

@Component({
  selector: 'app-spellbook-copy',
  standalone: true,
  templateUrl: './spellbook-copy.html',
  styleUrl: './spellbook-copy.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SpellbookCopy implements OnInit {
  readonly inventory = input<CharacterMagicItemInventoryResponse | null>(null);
  readonly accessToken = input.required<string>();
  readonly copied = output<string>();
  private readonly magicItemApi = inject(PublicCharacterMagicItemApiService);
  private readonly spellApi = inject(PlayerSpellApiService);
  protected readonly copyOpen = signal(false);
  protected readonly ritualCopyMode = signal(false);
  protected readonly copyBusy = signal(false);
  protected readonly copyPolicyId = signal<number | null>(null);
  protected readonly copySourceId = signal<number | null>(null);
  protected readonly copyDestinationId = signal<number | null>(null);
  protected readonly copySpellId = signal<number | null>(null);
  protected readonly copyArcana = signal<number | null>(null);
  protected readonly copyQuote = signal<{spellLevel: number; baseCostCp: number; baseMinutes: number; sourceType: string; arcanaDC: number | null} | null>(null);
  protected readonly copyError = signal('');
  protected readonly copyPolicies = signal<{id: number; label: string}[]>([]);
  protected readonly canCopyWizardSpells = computed(() => this.copyPolicies().length === 1);
  protected readonly pactBooks = computed(() => (this.inventory()?.ownedItems ?? []).filter(item => item.magicItem.category === 'spellbook' && item.pactTome === true && item.pactTomeOwnerId === this.inventory()?.characterId));
  protected readonly copyBooks = computed(() => this.ritualCopyMode() ? this.pactBooks() : (this.inventory()?.ownedItems ?? []).filter(item => item.magicItem.category === 'spellbook' && item.pactTome !== true));
  protected readonly copySources = computed(() => (this.inventory()?.ownedItems ?? []).filter(item => item.magicItem.category === 'spellbook' || item.magicItem.category === 'scroll'));
  protected readonly copySpells = computed(() => {
    const source = this.copySources().find(item => item.id === this.copySourceId());
    return source?.magicItem.category === 'spellbook' ? (source.spellbookSpells ?? []).filter(spell => !this.ritualCopyMode() || spell.level > 0).map(spell => ({id: spell.spellId, name: spell.name, level: spell.level}))
      : source?.inscribedSpellId ? [{id: source.inscribedSpellId, name: source.inscribedSpellName ?? 'Sort inscrit', level: 0}] : [];
  });
  protected openSpellCopy(): void {
    this.ritualCopyMode.set(false);
    this.copyOpen.set(true); this.copyError.set(''); this.copyQuote.set(null);
    // La progression est résolue lors du chargement du portail ; aucun choix joueur.
    if (!this.canCopyWizardSpells()) { this.copyOpen.set(false); return; }
    this.copyPolicyId.set(this.copyPolicies()[0].id);
  }
  protected openRitualCopy(): void {
    this.ritualCopyMode.set(true);
    this.copyPolicyId.set(null);
    this.copySourceId.set(null); this.copyDestinationId.set(null); this.copySpellId.set(null);
    this.copyArcana.set(null); this.copyQuote.set(null); this.copyError.set(''); this.copyOpen.set(true);
  }
  protected resetCopyQuote(): void { this.copyQuote.set(null); this.copyError.set(''); }
  protected requestCopyQuote(): void {
    const policyId = this.copyPolicyId(), sourceOwnedItemId = this.copySourceId(), destinationOwnedItemId = this.copyDestinationId(), spellId = this.copySpellId();
    if ((!this.ritualCopyMode() && !policyId) || !sourceOwnedItemId || !destinationOwnedItemId || !spellId) return;
    this.copyBusy.set(true); this.copyError.set(''); this.copyQuote.set(null);
    const request = this.ritualCopyMode()
      ? this.magicItemApi.pactRitualCopyQuote(this.accessToken(), {sourceOwnedItemId, destinationOwnedItemId, spellId})
      : this.magicItemApi.spellCopyQuote(this.accessToken(), {policyId: policyId!, sourceOwnedItemId, destinationOwnedItemId, spellId});
    request.subscribe({
      next: quote => {this.copyQuote.set(quote); this.copyBusy.set(false);},
      error: (error: HttpErrorResponse) => {this.copyBusy.set(false); this.copyError.set(error.error?.detail ?? error.error?.message ?? 'Copie impossible.');},
    });
  }
  protected confirmSpellCopy(): void {
    const quote = this.copyQuote(), policyId = this.copyPolicyId(), sourceOwnedItemId = this.copySourceId(), destinationOwnedItemId = this.copyDestinationId(), spellId = this.copySpellId();
    if (!quote || (!this.ritualCopyMode() && !policyId) || !sourceOwnedItemId || !destinationOwnedItemId || !spellId || this.copyBusy()) return;
    if (quote.sourceType === 'scroll' && (this.copyArcana() === null || !Number.isInteger(this.copyArcana()))) return;
    this.copyBusy.set(true); this.copyError.set('');
    const payload = {sourceOwnedItemId, destinationOwnedItemId, spellId, confirmed: true as const,
      ...(quote.sourceType === 'scroll' ? {arcanaTotal: this.copyArcana()!} : {})};
    const request = this.ritualCopyMode()
      ? this.magicItemApi.pactRitualCopy(this.accessToken(), payload)
      : this.magicItemApi.spellCopy(this.accessToken(), {...payload, policyId: policyId!});
    request.subscribe({
      next: result => {
        this.copyBusy.set(false); this.copyOpen.set(false); this.copyQuote.set(null);
        this.copied.emit(result.success ? 'Sort recopié dans le grimoire.' : 'Échec du test : parchemin consommé, sort non inscrit.');
      },
      error: (error: HttpErrorResponse) => {this.copyBusy.set(false); this.copyError.set(error.error?.detail ?? error.error?.message ?? 'Erreur pendant la copie. Vérifier l’état des objets avant de réessayer.');},
    });
  }

  ngOnInit(): void {
    this.spellApi.get(this.accessToken()).subscribe({
      next: view => {
        const wizardPolicies = view.preparations
          .filter(p => p.learningStyle === 'spellbook' && /magicien/i.test(p.source))
          .map(p => ({id: p.policyId, label: p.source}));
        this.copyPolicies.set(wizardPolicies);
        this.copyPolicyId.set(wizardPolicies.length === 1 ? wizardPolicies[0].id : null);
      },
      error: () => this.copyPolicies.set([]),
    });
  }
  protected formatPrice(copper: number | null | undefined): string {
    if (copper == null) return 'Non renseigné';
    if (copper === 0) return '0 pc';
    return copper >= 100 ? `${(copper / 100).toLocaleString('fr-FR', { maximumFractionDigits: 2 })} po` : `${copper} pc`;
  }
}

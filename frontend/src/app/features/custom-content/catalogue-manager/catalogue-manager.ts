import { Component, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { finalize } from 'rxjs';
import { CustomContentApiService } from '@core/services/custom-content-api.service';
import { PersonalCatalogueCategory, PersonalCatalogueItem } from '@core/models/personal-catalogue.model';

type Field = { key: string; label: string; kind: 'text' | 'number' | 'boolean' | 'json'; required?: boolean; min?: number; max?: number; createOnly?: boolean };
const common: Field[] = [
  { key: 'name', label: 'Nom', kind: 'text', required: true },
  { key: 'slug', label: 'Identifiant (slug)', kind: 'text', required: true, createOnly: true },
  { key: 'description', label: 'Description', kind: 'text' },
];
const specifics: Record<PersonalCatalogueCategory, Field[]> = {
  classes: [
    { key: 'hitDie', label: 'Dé de vie', kind: 'number', min: 1 },
    { key: 'subclassSelectionLevel', label: 'Niveau de choix de sous-classe', kind: 'number', min: 1, max: 20 },
    { key: 'spellcastingProgression', label: 'Progression magique (valeur backend)', kind: 'text' },
  ],
  subclasses: [
    { key: 'characterClassId', label: 'ID de la classe parente', kind: 'number', required: true, min: 1, createOnly: true },
    { key: 'spellcastingProgression', label: 'Progression magique (valeur backend)', kind: 'text' },
  ],
  races: [
    { key: 'parentRaceId', label: 'ID de race parente (facultatif)', kind: 'number', min: 1, createOnly: true },
    { key: 'selectable', label: 'Sélectionnable', kind: 'boolean' },
    { key: 'walkingSpeed', label: 'Vitesse de marche', kind: 'number', min: 0 },
    { key: 'languageChoiceCount', label: 'Nombre de langues au choix', kind: 'number', min: 0 },
    { key: 'featChoiceCount', label: 'Nombre de dons au choix', kind: 'number', min: 0 },
    ...['sizeOptions','movementSpeeds','languages','senses','damageResistances','damageImmunities','conditionImmunities'].map(key => ({ key, label: key, kind: 'json' as const })),
  ],
  feats: [
    { key: 'repeatable', label: 'Répétable', kind: 'boolean' },
    { key: 'requiresAbilityChoice', label: 'Choix de caractéristique requis', kind: 'boolean' },
    { key: 'chosenAbilityIncrease', label: 'Augmentation de caractéristique', kind: 'number', min: 0 },
  ],
  progressions: [
    { key: 'minimumValue', label: 'Valeur minimale', kind: 'number' },
    { key: 'maximumValue', label: 'Valeur maximale (facultative)', kind: 'number' },
    { key: 'accentColor', label: 'Couleur (CSS)', kind: 'text' },
    { key: 'gainLabel', label: 'Libellé de gain', kind: 'text' },
    { key: 'spendLabel', label: 'Libellé de dépense', kind: 'text' },
  ],
};
@Component({ selector: 'app-catalogue-manager', imports: [FormsModule], templateUrl: './catalogue-manager.html', styleUrl: './catalogue-manager.scss' })
export class CatalogueManager {
  readonly category = input.required<PersonalCatalogueCategory>();
  readonly busyChange = output<boolean>();
  private readonly api = inject(CustomContentApiService);
  readonly entries = signal<PersonalCatalogueItem[]>([]);
  readonly search = signal(''); readonly selectedId = signal<number | null>(null);
  readonly editing = signal(false); readonly busy = signal(false); readonly error = signal(''); readonly success = signal('');
  readonly form = signal<Record<string, unknown>>({});
  readonly fields = computed(() => [...common, ...specifics[this.category()]]);
  readonly filtered = computed(() => this.entries().filter(e => `${e.name} ${e.slug}`.toLocaleLowerCase('fr').includes(this.search().toLocaleLowerCase('fr'))));
  ngOnInit() { this.load(); }
  private setBusy(v: boolean) { this.busy.set(v); this.busyChange.emit(v); }
  load() {
    this.setBusy(true); this.error.set('');
    this.api.personalCatalogue(this.category()).pipe(finalize(() => this.setBusy(false))).subscribe({
      next: items => this.entries.set(items),
      error: () => this.error.set('Chargement impossible. Vérifie la connexion et les autorisations.'),
    });
  }
  canDiscard(): boolean { return !this.editing() || window.confirm('Abandonner les modifications non enregistrées ?'); }
  open(item: PersonalCatalogueItem | null) {
    if (this.busy() || !this.canDiscard()) return;
    this.selectedId.set(item?.id ?? null); this.error.set(''); this.success.set('');
    const form: Record<string, unknown> = {};
    for (const field of this.fields()) {
      const value = item?.[field.key];
      form[field.key] = field.kind === 'json' ? JSON.stringify(value ?? [], null, 2) : value ?? (field.kind === 'boolean' ? false : field.kind === 'number' ? null : '');
    }
    if (!item) { form['hitDie'] = 6; form['subclassSelectionLevel'] = 3; form['minimumValue'] = 0; }
    this.form.set(form); this.editing.set(true);
  }
  setField(key: string, value: unknown) { this.form.update(f => ({ ...f, [key]: value })); }
  fieldValue(key: string): unknown { return this.form()[key]; }
  close() { if (this.canDiscard()) this.editing.set(false); }
  save() {
    if (this.busy()) return;
    const id = this.selectedId(); const payload: Record<string, unknown> = {};
    for (const field of this.fields()) {
      if (id !== null && field.createOnly) continue;
      const value = this.form()[field.key];
      if (field.required && (value === null || value === undefined || value === '')) { this.error.set(`Champ obligatoire : ${field.label}`); return; }
      if (field.kind === 'json') {
        try { const parsed: unknown = JSON.parse(String(value || '[]')); if (!Array.isArray(parsed)) throw new Error('array'); payload[field.key] = parsed; }
        catch { this.error.set(`Liste JSON invalide : ${field.label}`); return; }
      } else if (field.kind === 'number') {
        if (value === '' || value === null || value === undefined) { if (field.required) { this.error.set(`Champ obligatoire : ${field.label}`); return; } continue; }
        const n = Number(value);
        if (!Number.isFinite(n) || (field.key !== 'walkingSpeed' && !Number.isInteger(n)) || (field.min !== undefined && n < field.min) || (field.max !== undefined && n > field.max)) { this.error.set(`Valeur invalide : ${field.label}`); return; }
        payload[field.key] = n;
      } else if (field.kind === 'boolean') payload[field.key] = Boolean(value);
      else if (value !== '' && value !== null && value !== undefined) payload[field.key] = String(value);
      else if (field.key === 'description') payload[field.key] = null;
    }
    this.setBusy(true); this.error.set('');
    this.api.savePersonalCatalogue(this.category(), id, payload).pipe(finalize(() => this.setBusy(false))).subscribe({
      next: item => { this.editing.set(false); this.selectedId.set(item.id); this.success.set(id === null ? 'Référence créée.' : 'Référence modifiée.'); this.load(); },
      error: err => this.error.set(err?.error?.message || (err?.status === 409 ? 'Conflit avec une référence existante.' : 'Enregistrement impossible.')),
    });
  }
}

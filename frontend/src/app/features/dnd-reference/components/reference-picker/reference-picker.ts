import { Component, computed, forwardRef, input, signal } from '@angular/core';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';

export interface ReferenceOption { id: number; name: string; origin?: string; custom?: boolean; }

@Component({
  selector: 'app-reference-picker',
  providers: [{ provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => ReferencePicker), multi: true }],
  template: `
    <input type="search" [attr.aria-label]="'Rechercher : ' + label()" placeholder="Rechercher par nom…"
      [disabled]="disabled()" [value]="search()" (input)="search.set($any($event.target).value)" (blur)="touch()" />
    <select [attr.aria-label]="label()" [disabled]="disabled()" [value]="value() ?? ''"
      (change)="choose($any($event.target).value)" (blur)="touch()">
      <option value="" [disabled]="!nullable()">{{ nullable() ? 'Aucune' : 'Sélectionner…' }}</option>
      @for (option of filtered(); track option.id) {
        <option [value]="option.id">{{ option.name }}{{ origin(option) ? ' — ' + origin(option) : '' }}</option>
      }
    </select>
    @if (search() && filtered().length === 0) { <small>Aucune référence accessible trouvée.</small> }
  `,
  styles: [`:host{display:grid;gap:.4rem;min-width:0} input,select{box-sizing:border-box;width:100%;min-width:0;padding:.65rem;border:1px solid #645333;border-radius:4px;background:#14121b;color:#eee8df;font:inherit} :focus-visible{outline:2px solid #d6b45e;outline-offset:2px} :disabled{opacity:.65} small{color:#c4b99e}`],
})
export class ReferencePicker implements ControlValueAccessor {
  readonly options = input<readonly ReferenceOption[]>([]);
  readonly label = input('Référence');
  readonly nullable = input(true);
  readonly value = signal<number | null>(null);
  readonly search = signal('');
  readonly disabled = signal(false);
  readonly filtered = computed(() => {
    const q = this.normalize(this.search());
    return this.options().filter(option => option.id === this.value() || this.normalize(option.name).includes(q));
  });
  private change: (value: number | null) => void = () => {};
  touch: () => void = () => {};
  origin(option: ReferenceOption): string { return option.origin ?? (option.custom === undefined ? '' : option.custom ? 'CUSTOM' : 'OFFICIAL'); }
  private normalize(value: string) { return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr'); }
  writeValue(value: number | null) { this.value.set(value); this.search.set(''); }
  registerOnChange(fn: (value: number | null) => void) { this.change = fn; }
  registerOnTouched(fn: () => void) { this.touch = fn; }
  setDisabledState(value: boolean) { this.disabled.set(value); }
  choose(raw: string) {
    if (this.disabled()) return;
    const value = raw === '' ? null : Number(raw);
    if ((value === null && !this.nullable()) || (value !== null && !this.options().some(option => option.id === value))) return;
    this.value.set(value); this.change(value); this.touch();
  }
}

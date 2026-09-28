import { afterNextRender, Component, DestroyRef, ElementRef, inject, input, OnInit, output, signal, viewChild } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule, ValidatorFn } from '@angular/forms';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize } from 'rxjs';
import { CreateCustomResourcePayload, CustomResource, CustomScalingAbility, UpdateCustomResourcePayload } from '@core/models/custom-content.model';
import { CustomContentApiService } from '@core/services/custom-content-api.service';

const integer = (minimum: number): ValidatorFn => control =>
  typeof control.value === 'number' && Number.isInteger(control.value) && control.value >= minimum && control.value <= 2147483647
    ? null : { integer: true };
const validName: ValidatorFn = control => typeof control.value === 'string' && control.value.trim().length > 0
  && Array.from(control.value.trim()).length <= 150 && !control.value.includes('\0') ? null : { name: true };

@Component({
  selector: 'app-resource-editor',
  imports: [ReactiveFormsModule],
  templateUrl: './resource-editor.html',
  styleUrl: './resource-editor.scss',
})
export class ResourceEditor implements OnInit {
  readonly resource = input<CustomResource | null>(null);
  readonly saved = output<CustomResource>();
  readonly closed = output<void>();
  readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly mechanicsFrozen = signal(false);
  protected readonly attempted = signal(false);
  private readonly api = inject(CustomContentApiService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly fb = inject(FormBuilder);
  private readonly nameInput = viewChild<ElementRef<HTMLInputElement>>('nameInput');
  protected readonly rechargeOptions = [
    { value: 'none', label: 'Aucune recharge automatique' },
    { value: 'short-rest', label: 'Repos court' },
    { value: 'long-rest', label: 'Repos long' },
  ] as const;
  protected readonly maximumOptions = [
    { value: 'fixed', label: 'Maximum fixe' },
    { value: 'proficiency-bonus', label: 'Selon le bonus de maîtrise' },
    { value: 'ability-modifier', label: 'Selon un modificateur de caractéristique' },
  ] as const;
  protected readonly abilities = [
    { value: 'strength', label: 'Force' }, { value: 'dexterity', label: 'Dextérité' },
    { value: 'constitution', label: 'Constitution' }, { value: 'intelligence', label: 'Intelligence' },
    { value: 'wisdom', label: 'Sagesse' }, { value: 'charisma', label: 'Charisme' },
  ] as const;
  protected readonly form = this.fb.group({
    name: this.fb.nonNullable.control('', validName),
    description: this.fb.nonNullable.control('', control => control.value.includes('\0') ? { invalidText: true } : null),
    rechargeType: this.fb.nonNullable.control<CustomResource['rechargeType']>('none', control =>
      this.rechargeOptions.some(option => option.value === control.value) ? null : { enum: true }),
    maximumType: this.fb.nonNullable.control<CustomResource['maximumType']>('fixed', control =>
      this.maximumOptions.some(option => option.value === control.value) ? null : { enum: true }),
    baseMaximum: this.fb.control<number | null>(0, integer(0)),
    multiplier: this.fb.control<number | null>(1, integer(1)),
    minimumMaximum: this.fb.control<number | null>(0, integer(0)),
    scalingAbility: this.fb.control<CustomScalingAbility | null>(null),
  }, { validators: group => group.get('maximumType')?.value === 'ability-modifier'
    && !this.abilities.some(ability => ability.value === group.get('scalingAbility')?.value) ? { ability: true } : null });

  constructor() {
    afterNextRender(() => this.nameInput()?.nativeElement.focus());
    this.form.controls.maximumType.valueChanges.pipe(takeUntilDestroyed()).subscribe(type => {
      if (type !== 'ability-modifier') this.form.controls.scalingAbility.setValue(null);
      // An invalid multiplier must not remain hidden when switching to a fixed maximum.
      if (type === 'fixed' && this.form.controls.multiplier.invalid) this.form.controls.multiplier.setValue(1);
    });
  }

  ngOnInit(): void {
    const resource = this.resource();
    if (resource) this.form.reset({
      name: resource.name, description: resource.description ?? '', rechargeType: resource.rechargeType,
      maximumType: resource.maximumType, baseMaximum: resource.baseMaximum, multiplier: resource.multiplier,
      minimumMaximum: resource.minimumMaximum, scalingAbility: resource.scalingAbility,
    }, { emitEvent: false });
  }

  canDiscard(): boolean {
    return !this.saving() && (!this.form.dirty || window.confirm('Des modifications de ressource ne sont pas enregistrées. Les abandonner ?'));
  }

  protected cancel(): void { if (this.canDiscard()) this.closed.emit(); }

  protected submit(): void {
    if (this.saving()) return;
    this.attempted.set(true);
    this.form.markAllAsTouched();
    const descriptiveOnly = this.mechanicsFrozen();
    if (this.form.controls.name.invalid || this.form.controls.description.invalid || (!descriptiveOnly && this.form.invalid)) return;
    const value = this.form.getRawValue();
    const original = this.resource();
    const payload: CreateCustomResourcePayload = {
      name: value.name.trim(), description: value.description.trim() || null,
      rechargeType: value.rechargeType, maximumType: value.maximumType,
      baseMaximum: value.baseMaximum!, multiplier: value.multiplier!, minimumMaximum: value.minimumMaximum!,
      scalingAbility: value.scalingAbility,
    };
    const patch: UpdateCustomResourcePayload = {};
    if (original) {
      // Explicit whitelist and field-by-field comparison: no response metadata or
      // unchanged mechanical values can enter a descriptive PATCH.
      if (payload.name !== original.name) patch.name = payload.name;
      if (payload.description !== original.description) patch.description = payload.description;
      if (!descriptiveOnly) {
        if (payload.rechargeType !== original.rechargeType) patch.rechargeType = payload.rechargeType;
        if (payload.maximumType !== original.maximumType) patch.maximumType = payload.maximumType;
        if (payload.baseMaximum !== original.baseMaximum) patch.baseMaximum = payload.baseMaximum;
        if (payload.multiplier !== original.multiplier) patch.multiplier = payload.multiplier;
        if (payload.minimumMaximum !== original.minimumMaximum) patch.minimumMaximum = payload.minimumMaximum;
        if (payload.scalingAbility !== original.scalingAbility) patch.scalingAbility = payload.scalingAbility;
      }
      if (Object.keys(patch).length === 0) { this.closed.emit(); return; }
    }
    this.saving.set(true); this.error.set(null);
    const request = original ? this.api.updateResource(original.id, patch) : this.api.createResource(payload);
    request.pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.saving.set(false))).subscribe({
      next: resource => this.saved.emit(resource),
      error: (error: unknown) => {
        if (error instanceof HttpErrorResponse && error.status === 409 && original) {
          this.mechanicsFrozen.set(true);
          this.error.set('Cette ressource est déjà utilisée. Ses paramètres mécaniques ne peuvent plus être modifiés. Vos saisies sont conservées ; seuls le nom et la description peuvent être enregistrés.');
        } else if (error instanceof HttpErrorResponse && error.status === 400) {
          const body: unknown = error.error;
          const message = typeof body === 'object' && body !== null && 'message' in body && typeof body.message === 'string'
            ? body.message : 'Vérifiez les valeurs saisies.';
          this.error.set(message);
        } else if (error instanceof HttpErrorResponse && error.status === 404) {
          this.error.set('Cette ressource n’est plus disponible. Fermez l’éditeur pour revenir au catalogue.');
        } else {
          this.error.set('La ressource n’a pas pu être enregistrée. Vos saisies sont conservées, vous pouvez réessayer.');
        }
      },
    });
  }
}

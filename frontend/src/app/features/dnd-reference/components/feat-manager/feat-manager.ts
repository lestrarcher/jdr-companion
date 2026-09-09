import {
  Component,
  computed,
  inject,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  AbilityKey,
  AbilityReference,
  DndReferenceApiService,
  FeatReference,
  SaveFeatPayload,
} from '../../../../core/services/dnd-reference-api.service';

interface FeatForm {
  slug: string;
  name: string;
  description: string;
  repeatable: boolean;
  requiresAbilityChoice: boolean;
  chosenAbilityIncrease: number;
  allowedAbilities: AbilityKey[];
  custom: boolean;
}

@Component({
  selector: 'app-feat-manager',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './feat-manager.html',
  styleUrl: './feat-manager.scss',
})
export class FeatManager {
  private readonly api =
    inject(DndReferenceApiService);

  protected readonly feats =
    signal<FeatReference[]>([]);

  protected readonly abilities =
    signal<AbilityReference[]>([]);

  protected readonly selectedFeatId =
    signal<number | null>(null);

  protected readonly search = signal('');
  protected readonly loading = signal(true);
  protected readonly submitting = signal(false);
  protected readonly deleting = signal(false);

  protected readonly error =
    signal<string | null>(null);

  protected readonly success =
    signal<string | null>(null);

  protected form: FeatForm =
    this.emptyForm();

  protected readonly selectedFeat = computed(() => {
    const selectedId =
      this.selectedFeatId();

    return this.feats().find(
      feat => feat.id === selectedId,
    ) ?? null;
  });

  protected readonly filteredFeats = computed(() => {
    const search =
      this.normalizeSearch(this.search());

    if (!search) {
      return this.feats();
    }

    return this.feats().filter(feat =>
      this.normalizeSearch(
        `${feat.name} ${feat.slug} ${feat.description ?? ''}`,
      ).includes(search),
    );
  });

  public constructor() {
    this.loadData();
  }

  protected selectFeat(
    feat: FeatReference,
  ): void {
    this.selectedFeatId.set(feat.id);

    this.form = {
      slug: feat.slug,
      name: feat.name,
      description: feat.description ?? '',
      repeatable: feat.repeatable,
      requiresAbilityChoice:
        feat.requiresAbilityChoice,
      chosenAbilityIncrease:
        feat.chosenAbilityIncrease,
      allowedAbilities:
        [...feat.allowedAbilities],
      custom: feat.custom,
    };

    this.clearMessages();
  }

  protected startCreation(): void {
    this.selectedFeatId.set(null);
    this.form = this.emptyForm();
    this.clearMessages();
  }

  protected save(): void {
    if (this.submitting()) {
      return;
    }

    if (
      !this.form.name.trim()
      || !this.form.slug.trim()
    ) {
      this.error.set(
        'Le nom et le slug sont obligatoires.',
      );
      return;
    }

    const payload: SaveFeatPayload = {
      slug: this.form.slug.trim(),
      name: this.form.name.trim(),
      description:
        this.form.description.trim() || null,
      repeatable: this.form.repeatable,
      requiresAbilityChoice:
        this.form.requiresAbilityChoice,
      chosenAbilityIncrease:
        this.form.requiresAbilityChoice
          ? Number(
              this.form.chosenAbilityIncrease,
            )
          : 0,
      allowedAbilities:
        this.form.requiresAbilityChoice
          ? [...this.form.allowedAbilities]
          : [],
      custom: this.form.custom,
    };

    const selectedId =
      this.selectedFeatId();

    const request =
      selectedId === null
        ? this.api.createFeat(payload)
        : this.api.updateFeat(
            selectedId,
            payload,
          );

    this.submitting.set(true);
    this.clearMessages();

    request.subscribe({
      next: response => {
        this.replaceFeat(response.feat);
        this.selectFeat(response.feat);

        this.success.set(
          selectedId === null
            ? `${response.feat.name} a bien été créé.`
            : `${response.feat.name} a bien été mis à jour.`,
        );

        this.submitting.set(false);
      },
      error: error => {
        this.error.set(
          error.error?.error
          ?? error.error?.message
          ?? 'Impossible d’enregistrer ce don.',
        );

        this.submitting.set(false);
      },
    });
  }

  protected deleteSelectedFeat(): void {
    const feat = this.selectedFeat();

    if (
      !feat
      || this.deleting()
    ) {
      return;
    }

    if (
      !confirm(
        `Supprimer définitivement le don "${feat.name}" ?`,
      )
    ) {
      return;
    }

    this.deleting.set(true);
    this.clearMessages();

    this.api.deleteFeat(feat.id)
      .subscribe({
        next: response => {
          this.feats.update(feats =>
            feats.filter(
              item => item.id !== feat.id,
            ),
          );

          this.startCreation();
          this.success.set(response.message);
          this.deleting.set(false);
        },
        error: error => {
          this.error.set(
            error.error?.error
            ?? error.error?.message
            ?? 'Impossible de supprimer ce don.',
          );

          this.deleting.set(false);
        },
      });
  }

  protected toggleAbility(
    ability: AbilityKey,
    checked: boolean,
  ): void {
    if (checked) {
      if (
        !this.form.allowedAbilities.includes(
          ability,
        )
      ) {
        this.form.allowedAbilities = [
          ...this.form.allowedAbilities,
          ability,
        ];
      }

      return;
    }

    this.form.allowedAbilities =
      this.form.allowedAbilities.filter(
        value => value !== ability,
      );
  }

  protected abilitySelected(
    ability: AbilityKey,
  ): boolean {
    return this.form.allowedAbilities
      .includes(ability);
  }

  protected abilityChoiceChanged(): void {
    if (
      !this.form.requiresAbilityChoice
    ) {
      this.form.chosenAbilityIncrease = 0;
      this.form.allowedAbilities = [];
    }
  }

  protected generateSlug(): void {
    this.form.slug =
      this.slugify(this.form.name);
  }

  protected updateSearch(
    value: string,
  ): void {
    this.search.set(value);
  }

  private loadData(): void {
    this.loading.set(true);
    this.error.set(null);

    this.api.getFeats().subscribe({
      next: response => {
        this.feats.set(
          this.sortFeats(response.feats),
        );

        this.abilities.set(
          response.abilities,
        );

        this.loading.set(false);
      },
      error: error => {
        this.error.set(
          error.error?.error
          ?? error.error?.message
          ?? 'Impossible de charger les dons.',
        );

        this.loading.set(false);
      },
    });
  }

  private replaceFeat(
    savedFeat: FeatReference,
  ): void {
    const exists =
      this.feats().some(
        feat => feat.id === savedFeat.id,
      );

    const feats = exists
      ? this.feats().map(feat =>
          feat.id === savedFeat.id
            ? savedFeat
            : feat,
        )
      : [
          ...this.feats(),
          savedFeat,
        ];

    this.feats.set(
      this.sortFeats(feats),
    );
  }

  private sortFeats(
    feats: FeatReference[],
  ): FeatReference[] {
    return [...feats].sort(
      (first, second) =>
        first.name.localeCompare(
          second.name,
          'fr',
        ),
    );
  }

  private emptyForm(): FeatForm {
    return {
      slug: '',
      name: '',
      description: '',
      repeatable: false,
      requiresAbilityChoice: false,
      chosenAbilityIncrease: 0,
      allowedAbilities: [],
      custom: true,
    };
  }

  private slugify(
    value: string,
  ): string {
    return value
      .normalize('NFD')
      .replace(
        /[\u0300-\u036f]/g,
        '',
      )
      .toLocaleLowerCase('fr')
      .replace(
        /[^a-z0-9]+/g,
        '-',
      )
      .replace(
        /^-+|-+$/g,
        '',
      );
  }

  private normalizeSearch(
    value: string,
  ): string {
    return value
      .normalize('NFD')
      .replace(
        /[\u0300-\u036f]/g,
        '',
      )
      .toLocaleLowerCase('fr')
      .trim();
  }

  private clearMessages(): void {
    this.error.set(null);
    this.success.set(null);
  }
}

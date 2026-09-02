import {
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';

type TipCategory = 'rule' | 'advice' | 'lore';

interface Tip {
  category: TipCategory;
  label: string;
  text: string;
}

@Component({
  selector: 'app-tip-bar',
  imports: [],
  templateUrl: './tip-bar.html',
  styleUrl: './tip-bar.scss',
})
export class TipBar {
  private readonly destroyRef = inject(DestroyRef);

  protected readonly tips: Tip[] = [
    {
      category: 'rule',
      label: 'Bousculade',
      text: 'Par une action bonus, vous pouvez tenter de repousser de 1,50 m une créature à votre portée ne dépassant pas votre taille de plus d’une catégorie. Faites un test d’Athlétisme opposé à son Athlétisme ou son Acrobaties.',
    },
    {
      category: 'rule',
      label: 'Boire une potion',
      text: 'Vous pouvez boire une potion par une action ou une action bonus. Administrer une potion à une autre créature nécessite une action.',
    },
    {
      category: 'advice',
      label: 'Conseil aux aventuriers',
      text: 'Pensez à utiliser votre Inspiration avant qu’il ne soit trop tard.',
    },
    {
      category: 'lore',
      label: 'Murmure de Barovie',
      text: 'Les invités du comte sont priés de conserver leur masque jusqu’à minuit.',
    },
  ];

  protected readonly currentIndex = signal(0);

  protected readonly currentTip = computed(
    () => this.tips[this.currentIndex()],
  );

  public constructor() {
    const rotationTimer = window.setInterval(() => {
      this.showNextTip();
    }, 8_000);

    this.destroyRef.onDestroy(() => {
      window.clearInterval(rotationTimer);
    });
  }

  private showNextTip(): void {
    const nextIndex =
      (this.currentIndex() + 1) % this.tips.length;

    this.currentIndex.set(nextIndex);
  }
}

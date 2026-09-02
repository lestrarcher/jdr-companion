import {
  Component,
  computed,
  OnDestroy,
  signal,
} from '@angular/core';

type MemorialRole = 'PJ' | 'PNJ';

interface MemorialEntry {
  name: string;
  role: MemorialRole;
  epitaph: string;
  deathDay: string;
  imageUrl?: string;
  imageAlt?: string;
}

@Component({
  selector: 'app-memorial-panel',
  imports: [],
  templateUrl: './memorial-panel.html',
  styleUrl: './memorial-panel.scss',
})
export class MemorialPanel implements OnDestroy {
  protected readonly memorialEntries: MemorialEntry[] = [
    {
      name: 'Oskar',
      role: 'PJ',
      epitaph: 'Le guerrier volé',
      deathDay: 'Jour 7',
      imageUrl: '/assets/memorial/oskar.png',
      imageAlt: `Portrait d'Oskar`,
    },
    {
      name: 'Hestra',
      role: 'PJ',
      epitaph: 'Le loup sans meute',
      deathDay: '7',
      imageUrl: '/assets/memorial/hestra.png',
      imageAlt: `Portrait d'Hestra`,
    },
    {
      name: 'Greto',
      role: 'PJ',
      epitaph: 'Le loup sans meute',
      deathDay: 'Jour 10',
      imageUrl: '/assets/memorial/greto.png',
      imageAlt: 'Portrait de Greto',
    },
    {
      name: 'Rhéa',
      role: 'PJ',
      epitaph: 'La dernière flèche',
      deathDay: 'Jour 10',
      imageUrl: '/assets/memorial/rhea.png',
      imageAlt: 'Portrait de Rhéa',
    },
    {
      name: 'Milivoj',
      role: 'PNJ',
      epitaph: 'Le grand frère',
      deathDay: '7',
      imageUrl: '/assets/memorial/milivoj.png',
      imageAlt: 'Portrait de Milivoj',
    },
    {
      name: 'Père Lucian',
      role: 'PNJ',
      epitaph: 'Symbôle de lumière',
      deathDay: 'Jour 7',
    },
    {
      name: 'Attila',
      role: 'PNJ',
      epitaph: 'Le Roi des Uns',
      deathDay: 'Jour 7',
      imageUrl: '/assets/memorial/attila.png',
      imageAlt: `Portrait d'Attila`,
    },
  ];

  protected readonly activeIndex = signal(
    Math.floor(Math.random() * this.memorialEntries.length),
  );

  protected readonly activeEntry = computed(
    () => this.memorialEntries[this.activeIndex()],
  );

  private readonly rotationTimer: ReturnType<typeof setInterval> =
    setInterval(() => {
      this.activeIndex.update(currentIndex =>
        this.pickRandomIndex(currentIndex),
      );
    }, 9000);

  private pickRandomIndex(currentIndex: number): number {
    const entryCount = this.memorialEntries.length;

    if (entryCount <= 1) {
      return currentIndex;
    }

    const randomOffset =
      Math.floor(Math.random() * (entryCount - 1)) + 1;

    return (currentIndex + randomOffset) % entryCount;
  }

  ngOnDestroy(): void {
    clearInterval(this.rotationTimer);
  }
}

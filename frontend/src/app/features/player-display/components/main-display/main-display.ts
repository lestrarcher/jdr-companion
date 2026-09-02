import { Component } from '@angular/core';

interface DisplayedImage {
  type: 'image';
  source: string;
  alt: string;
  title?: string;
  subtitle?: string;
}

@Component({
  selector: 'app-main-display',
  imports: [],
  templateUrl: './main-display.html',
  styleUrl: './main-display.scss',
})
export class MainDisplay {
  protected readonly displayedMedia: DisplayedImage | null = {
    type: 'image',
    source: '/assets/display/curse-of-strahd.png',
    alt: 'Coucerture La Malédiction de Strahd',
    title: 'La Malédiction de Strahd',
    subtitle: 'Bienvenue en Barovie',
  };
}

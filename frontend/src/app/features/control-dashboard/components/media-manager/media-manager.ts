import {
  Component,
  DestroyRef,
  OnInit,
  inject,
  input,
  output,
  signal,
} from '@angular/core';

import {
  finalize,
} from 'rxjs';

import {
  CampaignMedia,
  MediaApiService,
} from '@core/services/media-api.service';

@Component({
  selector: 'app-media-manager',
  imports: [],
  templateUrl: './media-manager.html',
  styleUrl: './media-manager.scss',
})
export class MediaManager implements OnInit {
  private readonly mediaApi =
    inject(MediaApiService);

  private readonly destroyRef =
    inject(DestroyRef);

  readonly campaignId =
    input.required<number>();

  readonly selectedUrl =
    input<string | null>(null);

  readonly mediaSelected =
    output<CampaignMedia>();

  protected readonly media =
    signal<CampaignMedia[]>([]);

  protected readonly selectedFile =
    signal<File | null>(null);

  protected readonly title =
    signal('');

  protected readonly loading =
    signal(true);

  protected readonly uploading =
    signal(false);

  protected readonly error =
    signal<string | null>(null);

  ngOnInit(): void {
    this.loadMedia();
  }

  protected selectFile(
    event: Event,
  ): void {
    const input =
      event.target as HTMLInputElement;

    this.selectedFile.set(
      input.files?.[0] ?? null,
    );

    this.error.set(null);
  }

  protected updateTitle(
    event: Event,
  ): void {
    const input =
      event.target as HTMLInputElement;

    this.title.set(input.value);
  }

  protected upload(
    fileInput: HTMLInputElement,
  ): void {
    const file = this.selectedFile();

    if (!file || this.uploading()) {
      return;
    }

    this.uploading.set(true);
    this.error.set(null);

    const subscription =
      this.mediaApi
        .upload(
          this.campaignId(),
          file,
          this.title(),
        )
        .pipe(
          finalize(() => {
            this.uploading.set(false);
          }),
        )
        .subscribe({
          next: (uploadedMedia) => {
            this.media.update(
              (currentMedia) => [
                uploadedMedia,
                ...currentMedia,
              ],
            );

            this.selectedFile.set(null);
            this.title.set('');
            fileInput.value = '';

            /*
             * L’image nouvellement envoyée devient
             * automatiquement l’image sélectionnée.
             */
            this.mediaSelected.emit(
              uploadedMedia,
            );
          },

          error: (error) => {
            this.error.set(
              error.error?.message ??
                'Impossible d’envoyer cette image.',
            );
          },
        });

    this.destroyRef.onDestroy(() => {
      subscription.unsubscribe();
    });
  }

  protected selectMedia(
    media: CampaignMedia,
  ): void {
    this.mediaSelected.emit(media);
  }

  protected displayTitle(
    media: CampaignMedia,
  ): string {
    return (
      media.title ??
      media.originalName
    );
  }

  private loadMedia(): void {
    this.loading.set(true);
    this.error.set(null);

    const subscription =
      this.mediaApi
        .list(this.campaignId())
        .pipe(
          finalize(() => {
            this.loading.set(false);
          }),
        )
        .subscribe({
          next: (media) => {
            this.media.set(media);
          },

          error: (error) => {
            this.error.set(
              error.error?.message ??
                'Impossible de charger les médias.',
            );
          },
        });

    this.destroyRef.onDestroy(() => {
      subscription.unsubscribe();
    });
  }
}

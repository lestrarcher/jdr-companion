import {
  Component,
  input,
  output,
} from '@angular/core';

import { CampaignMedia } from '@core/models/campaign.model';

@Component({
  selector: 'app-media-controls',
  imports: [],
  templateUrl: './media-controls.html',
  styleUrl: './media-controls.scss',
})
export class MediaControls {
  readonly media =
    input.required<CampaignMedia[]>();

  readonly selectedMediaId =
    input<string | null>(null);

  readonly disabled = input(false);

  readonly mediaSelected =
    output<CampaignMedia>();

  readonly mediaCleared =
    output<void>();

  protected selectMedia(
    media: CampaignMedia,
  ): void {
    if (this.disabled()) {
      return;
    }

    this.mediaSelected.emit(media);
  }

  protected clearMedia(): void {
    if (this.disabled()) {
      return;
    }

    this.mediaCleared.emit();
  }
}

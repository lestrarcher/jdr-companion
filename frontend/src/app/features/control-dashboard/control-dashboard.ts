import { Component, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';

import { CampaignMedia } from '@core/models/campaign.model';
import { LiveSessionService } from '@core/services/live-session.service';
import { STRAHD_CAMPAIGN } from '@data/campaigns/strahd.config';

@Component({
  selector: 'app-control-dashboard',
  imports: [ReactiveFormsModule],
  templateUrl: './control-dashboard.html',
  styleUrl: './control-dashboard.scss',
})
export class ControlDashboard {
  private readonly formBuilder = inject(FormBuilder);
  private readonly liveSessionService = inject(LiveSessionService);
  private readonly route = inject(ActivatedRoute);

  protected readonly campaign = STRAHD_CAMPAIGN;
  protected readonly liveState = this.liveSessionService.state;

  protected readonly worldForm = this.formBuilder.nonNullable.group({
    day: [1, [Validators.required, Validators.min(1)]],
    dayPeriod: ['', Validators.required],

    weatherId: ['', Validators.required],

    moonId: ['', Validators.required],

    locationName: ['', Validators.required],
    locationSubtitle: [''],
  });

  constructor() {
    const campaignId = this.route.snapshot.paramMap.get('campaignId');
    const sessionId = this.route.snapshot.paramMap.get('sessionId');

    if (!campaignId || !sessionId) {
      throw new Error('Identifiants de campagne ou de session manquants.');
    }

    if (campaignId !== this.campaign.id) {
      throw new Error(`Campagne inconnue : ${campaignId}`);
    }

    this.liveSessionService.initialize(this.campaign, sessionId);

    const state = this.liveState();

    if (state) {
      this.worldForm.patchValue({
        day: state.day ?? 1,
        dayPeriod: state.dayPeriod ?? '',
        weatherId: state.weather?.id ?? '',
        moonId: state.moon?.id ?? '',
        locationName: state.location?.name ?? '',
        locationSubtitle: state.location?.subtitle ?? '',
      });
    }
  }

  protected updateWorld(): void {
    if (this.worldForm.invalid) {
      this.worldForm.markAllAsTouched();
      return;
    }

    const values = this.worldForm.getRawValue();

    const selectedWeather = this.campaign.weatherStates.find(
      (weather) => weather.id === values.weatherId,
    );

    const selectedMoon = this.campaign.moonPhases.find(
      (moon) => moon.id === values.moonId,
    );

    if (!selectedWeather || !selectedMoon) {
      return;
    }

    this.liveSessionService.updateState({
      day: values.day,
      dayPeriod: values.dayPeriod,

      weather: selectedWeather,

      moon: selectedMoon,

      location: {
        name: values.locationName,
        subtitle: values.locationSubtitle,
      },
    });
  }

  protected displayMedia(media: CampaignMedia): void {
  this.liveSessionService.updateState({
    displayedMedia: {
      id: media.id,
      source: media.source,
      alt: media.alt,
      title: media.title,
      subtitle: media.subtitle,
      fit: media.fit,
    },
  });
}

  protected clearMedia(): void {
    this.liveSessionService.updateState({
      displayedMedia: undefined,
    });
  }

  protected toggleFog(): void {
    const state = this.liveState();

    if (!state) {
      return;
    }

    this.liveSessionService.updateState({
      fogEnabled: !state.fogEnabled,
    });
  }

  protected toggleCinematicMode(): void {
    const state = this.liveState();

    if (!state) {
      return;
    }

    this.liveSessionService.updateState({
      displayMode:
        state.displayMode === 'cinematic'
          ? 'normal'
          : 'cinematic',
    });
  }
}

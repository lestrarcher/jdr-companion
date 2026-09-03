import {
  Component,
  effect,
  inject,
  input,
  output,
} from '@angular/core';
import {
  FormBuilder,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';

import {
  LiveSessionState,
  MoonState,
  WeatherState,
} from '@core/models/live-session-state.model';

export interface WorldUpdate {
  day: number;
  dayPeriod: string;
  weather: WeatherState;
  moon: MoonState;

  location: {
    name: string;
    subtitle: string;
  };
}

@Component({
  selector: 'app-world-controls',
  imports: [ReactiveFormsModule],
  templateUrl: './world-controls.html',
  styleUrl: './world-controls.scss',
})
export class WorldControls {
  private readonly formBuilder =
    inject(FormBuilder);

  readonly state =
    input<LiveSessionState | null>();

  readonly weatherStates =
    input.required<WeatherState[]>();

  readonly moonPhases =
    input.required<MoonState[]>();

  readonly disabled = input(false);

  readonly worldUpdated =
    output<WorldUpdate>();

  protected readonly worldForm =
    this.formBuilder.nonNullable.group({
      day: [
        1,
        [
          Validators.required,
          Validators.min(1),
        ],
      ],

      dayPeriod: [
        '',
        Validators.required,
      ],

      weatherId: [
        '',
        Validators.required,
      ],

      moonId: [
        '',
        Validators.required,
      ],

      locationName: [
        '',
        Validators.required,
      ],

      locationSubtitle: [''],
    });

  constructor() {
    effect(() => {
      const state = this.state();

      if (!state) {
        return;
      }

      this.worldForm.patchValue(
        {
          day: state.day ?? 1,

          dayPeriod:
            state.dayPeriod ?? '',

          weatherId:
            state.weather?.id ?? '',

          moonId:
            state.moon?.id ?? '',

          locationName:
            state.location?.name ?? '',

          locationSubtitle:
            state.location?.subtitle ?? '',
        },
        {
          emitEvent: false,
        },
      );
    });
  }

  protected submit(): void {
    if (
      this.worldForm.invalid ||
      this.disabled()
    ) {
      this.worldForm.markAllAsTouched();
      return;
    }

    const values =
      this.worldForm.getRawValue();

    const weather =
      this.weatherStates().find(
        (candidate) =>
          candidate.id ===
          values.weatherId,
      );

    const moon =
      this.moonPhases().find(
        (candidate) =>
          candidate.id ===
          values.moonId,
      );

    if (!weather || !moon) {
      return;
    }

    this.worldUpdated.emit({
      day: values.day,
      dayPeriod: values.dayPeriod,
      weather,
      moon,

      location: {
        name: values.locationName.trim(),
        subtitle:
          values.locationSubtitle.trim(),
      },
    });
  }
}

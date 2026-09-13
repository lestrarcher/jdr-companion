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
} from '@core/models/live-session-state.model';
import {
  GameSessionApiResponse,
  MoonPhase,
} from '@core/services/game-session-api.service';
import {
  Weather,
} from '@core/services/weather-api.service';

export interface WorldUpdate {
  day: number;
  dayPeriod: string;

  weatherId: number | null;
  showWeather: boolean;

  moonPhase: MoonPhase | null;
  showMoonPhase: boolean;

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

  readonly session =
    input<GameSessionApiResponse | null>(
      null,
    );

  readonly weatherStates =
    input.required<Weather[]>();

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

      weatherId: [''],

      showWeather: [true],

      moonPhase: [''],

      showMoonPhase: [false],

      locationName: [
        '',
        Validators.required,
      ],

      locationSubtitle: [''],
    });

  constructor() {
    effect(() => {
      const state = this.state();
      const session = this.session();

      if (!state) {
        return;
      }

      this.worldForm.patchValue(
        {
          day: state.day ?? 1,

          dayPeriod:
            state.dayPeriod ?? '',

          weatherId:
            session?.weatherId !== null &&
            session?.weatherId !== undefined
              ? String(session.weatherId)
              : '',

          showWeather:
            session?.showWeather ?? true,

          moonPhase:
            session?.moonPhase ?? '',

          showMoonPhase:
            session?.showMoonPhase ?? false,

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

    const weatherId =
      values.weatherId === ''
        ? null
        : Number(values.weatherId);

    this.worldUpdated.emit({
      day: values.day,

      dayPeriod:
        values.dayPeriod,

      weatherId:
        weatherId !== null &&
        Number.isInteger(weatherId)
          ? weatherId
          : null,

      showWeather:
        values.showWeather,

      moonPhase:
        values.moonPhase === ''
          ? null
          : values.moonPhase as MoonPhase,

      showMoonPhase:
        values.showMoonPhase,

      location: {
        name:
          values.locationName.trim(),

        subtitle:
          values.locationSubtitle.trim(),
      },
    });
  }
}

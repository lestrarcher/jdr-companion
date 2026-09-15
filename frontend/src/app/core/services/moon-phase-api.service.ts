import {
  inject,
  Injectable,
} from '@angular/core';
import {
  HttpClient,
} from '@angular/common/http';
import {
  map,
  Observable,
} from 'rxjs';

import {
  MoonState,
} from '@core/models/live-session-state.model';

interface MoonPhaseListResponse {
  moonPhases: MoonState[];
}

@Injectable({
  providedIn: 'root',
})
export class MoonPhaseApiService {
  private readonly http =
    inject(HttpClient);

  list(): Observable<MoonState[]> {
    return this.http
      .get<MoonPhaseListResponse>(
        '/api/moon-phases',
      )
      .pipe(
        map(
          response =>
            response.moonPhases,
        ),
      );
  }
}

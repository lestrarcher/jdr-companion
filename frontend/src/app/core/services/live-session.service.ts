import {
  Injectable,
  signal,
} from '@angular/core';

import { CampaignConfig } from '@core/models/campaign.model';
import {
  FigurePanelMode,
  LiveSessionState,
} from '@core/models/live-session-state.model';

@Injectable({
  providedIn: 'root',
})
export class LiveSessionService {
  private readonly currentState =
    signal<LiveSessionState | null>(null);

  readonly state =
    this.currentState.asReadonly();

  private channel?: BroadcastChannel;
  private storageKey?: string;

  initialize(
    campaign: CampaignConfig,
    sessionId: string,
  ): void {
    this.channel?.close();

    const channelName =
      `jdr-companion:${campaign.id}:sessions:${sessionId}:live-state`;

    this.storageKey = channelName;

    const initialState: LiveSessionState = {
      ...campaign.initialLiveState,
      campaignId: campaign.id,
      sessionId,
      figurePanelMode:
        campaign.initialLiveState
          .figurePanelMode
        ?? 'memorial',
    };

    const storedState =
      this.loadStoredState();

    /*
     * On fusionne avec l’état initial pour que
     * les anciennes données du localStorage
     * récupèrent les nouveaux champs.
     */
    const restoredState: LiveSessionState =
      storedState
        ? {
            ...initialState,
            ...storedState,
            figurePanelMode:
              storedState.figurePanelMode
              ?? initialState.figurePanelMode,
          }
        : initialState;

    this.currentState.set(restoredState);

    if (
      typeof BroadcastChannel ===
      'undefined'
    ) {
      return;
    }

    this.channel =
      new BroadcastChannel(channelName);

    this.channel.onmessage = (
      event: MessageEvent<LiveSessionState>,
    ): void => {
      const receivedState: LiveSessionState = {
        ...initialState,
        ...event.data,
        figurePanelMode:
          event.data.figurePanelMode
          ?? initialState.figurePanelMode,
      };

      this.currentState.set(
        receivedState,
      );

      this.saveState(receivedState);
    };
  }

  updateState(
    changes: Partial<LiveSessionState>,
  ): void {
    const state =
      this.currentState();

    if (!state) {
      return;
    }

    const updatedState: LiveSessionState = {
      ...state,
      ...changes,
    };

    this.currentState.set(updatedState);
    this.saveState(updatedState);

    this.channel?.postMessage(
      updatedState,
    );
  }

  private loadStoredState():
    LiveSessionState | null {
    if (
      !this.storageKey
      || typeof localStorage === 'undefined'
    ) {
      return null;
    }

    const storedValue =
      localStorage.getItem(
        this.storageKey,
      );

    if (!storedValue) {
      return null;
    }

    try {
      return JSON.parse(
        storedValue,
      ) as LiveSessionState;
    } catch {
      localStorage.removeItem(
        this.storageKey,
      );

      return null;
    }
  }

  private saveState(
    state: LiveSessionState,
  ): void {
    if (
      !this.storageKey
      || typeof localStorage === 'undefined'
    ) {
      return;
    }

    localStorage.setItem(
      this.storageKey,
      JSON.stringify(state),
    );
  }
}

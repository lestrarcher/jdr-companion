import { Injectable, signal } from '@angular/core';

import {
  RestRequest,
  RestRequestStatus,
  RestType,
} from '@core/models/rest-request.model';

@Injectable({
  providedIn: 'root',
})
export class RestRequestService {
  private readonly requestsSignal = signal<RestRequest[]>([]);

  readonly requests = this.requestsSignal.asReadonly();

  private storageKey = '';
  private channel?: BroadcastChannel;

  initialize(campaignId: string, sessionId: string): void {
    this.channel?.close();

    this.storageKey =
      `jdr-companion:${campaignId}:sessions:${sessionId}:rest-requests`;

    if (typeof window === 'undefined') {
      return;
    }

    this.requestsSignal.set(this.loadStoredRequests());

    if (typeof BroadcastChannel === 'undefined') {
      return;
    }

    this.channel = new BroadcastChannel(this.storageKey);

    this.channel.onmessage = (
      event: MessageEvent<RestRequest[]>,
    ): void => {
      this.requestsSignal.set(event.data);
      this.saveLocally(event.data);
    };
  }

  createRequest(
    campaignId: string,
    sessionId: string,
    characterId: string,
    characterName: string,
    type: RestType,
  ): void {
    const requests = this.requestsSignal();

    const alreadyPending = requests.some(
      (request) =>
        request.characterId === characterId &&
        request.status === 'pending',
    );

    if (alreadyPending) {
      return;
    }

    const request: RestRequest = {
      id: crypto.randomUUID(),
      campaignId,
      sessionId,
      characterId,
      characterName,
      type,
      status: 'pending',
      requestedAt: new Date().toISOString(),
    };

    this.publish([...requests, request]);
  }

  resolveRequest(
    requestId: string,
    status: Extract<
      RestRequestStatus,
      'approved' | 'rejected'
    >,
  ): void {
    const requests = this.requestsSignal().map((request) => {
      if (
        request.id !== requestId ||
        request.status !== 'pending'
      ) {
        return request;
      }

      return {
        ...request,
        status,
        resolvedAt: new Date().toISOString(),
      };
    });

    this.publish(requests);
  }

  clearRequest(requestId: string): void {
    const requests = this.requestsSignal().filter(
      (request) => request.id !== requestId,
    );

    this.publish(requests);
  }

  private loadStoredRequests(): RestRequest[] {
    if (
      !this.storageKey ||
      typeof localStorage === 'undefined'
    ) {
      return [];
    }

    const storedValue = localStorage.getItem(this.storageKey);

    if (!storedValue) {
      return [];
    }

    try {
      return JSON.parse(storedValue) as RestRequest[];
    } catch {
      localStorage.removeItem(this.storageKey);
      return [];
    }
  }

  private publish(requests: RestRequest[]): void {
    this.requestsSignal.set(requests);
    this.saveLocally(requests);
    this.channel?.postMessage(requests);
  }

  private saveLocally(requests: RestRequest[]): void {
    if (
      !this.storageKey ||
      typeof localStorage === 'undefined'
    ) {
      return;
    }

    localStorage.setItem(
      this.storageKey,
      JSON.stringify(requests),
    );
  }
}

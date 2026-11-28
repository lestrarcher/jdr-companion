import '@angular/compiler';
import { signal } from '@angular/core';
import { EMPTY, Subject } from 'rxjs';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PlayerPortal } from './player-portal';

describe('Player portal profile polling', () => {
  let destroy: (() => void)[] = [];
  afterEach(() => {
    destroy.forEach(callback => callback());
    destroy = [];
    vi.restoreAllMocks();
    vi.useRealTimers();
  });

  function setup() {
    vi.useFakeTimers();
    const hidden = vi.spyOn(document, 'hidden', 'get').mockReturnValue(false);
    const component = Object.create(PlayerPortal.prototype) as any;
    Object.assign(component, {
      destroyRef: { onDestroy: (callback: () => void) => { destroy.push(callback); return () => {}; } },
      pendingRestRequest: signal(null), sessionStatus: signal('live'),
      restRequestApi: { getLatest: vi.fn(() => EMPTY) }, accessToken: 'test',
      refreshCharacterIfChanged: vi.fn(),
    });
    component.initializeRestRequestPolling();
    return { component, hidden };
  }

  it('refreshes every 30 seconds, skips hidden documents and refreshes on return', () => {
    const { component, hidden } = setup();
    vi.advanceTimersByTime(0);
    expect(component.refreshCharacterIfChanged).toHaveBeenCalledTimes(1);
    vi.advanceTimersByTime(29999);
    expect(component.refreshCharacterIfChanged).toHaveBeenCalledTimes(1);
    vi.advanceTimersByTime(1);
    expect(component.refreshCharacterIfChanged).toHaveBeenCalledTimes(2);
    hidden.mockReturnValue(true);
    document.dispatchEvent(new Event('visibilitychange'));
    vi.advanceTimersByTime(60000);
    expect(component.refreshCharacterIfChanged).toHaveBeenCalledTimes(2);
    hidden.mockReturnValue(false);
    document.dispatchEvent(new Event('visibilitychange'));
    expect(component.refreshCharacterIfChanged).toHaveBeenCalledTimes(3);
    destroy.forEach(callback => callback());
    destroy = [];
    document.dispatchEvent(new Event('visibilitychange'));
    vi.advanceTimersByTime(60000);
    expect(component.refreshCharacterIfChanged).toHaveBeenCalledTimes(3);
  });

  it('keeps rest decision polling at 5 seconds even when hidden', () => {
    const { component, hidden } = setup();
    component.pendingRestRequest.set({ id: 1 });
    hidden.mockReturnValue(true);
    vi.advanceTimersByTime(15000);
    expect(component.restRequestApi.getLatest).toHaveBeenCalledTimes(3);
    hidden.mockReturnValue(false);
    document.dispatchEvent(new Event('visibilitychange'));
    expect(component.refreshCharacterIfChanged).not.toHaveBeenCalled();
  });

  it('uses the existing in-flight guard when visibility and timer overlap', () => {
    const { component } = setup();
    component.refreshCharacterIfChanged = PlayerPortal.prototype['refreshCharacterIfChanged'];
    Object.assign(component, {
      character: signal({}), loading: signal(false), remoteSynchronizationEnabled: signal(true),
      characterStateService: { revision: () => 0 }, refreshPending: false,
      characterSessionStateApi: { getByAccessToken: vi.fn(() => new Subject()) },
    });
    vi.advanceTimersByTime(0);
    document.dispatchEvent(new Event('visibilitychange'));
    vi.advanceTimersByTime(30000);
    expect(component.characterSessionStateApi.getByAccessToken).toHaveBeenCalledTimes(1);
  });
});

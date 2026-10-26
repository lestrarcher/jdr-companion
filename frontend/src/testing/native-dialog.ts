import { afterAll, beforeAll } from 'vitest';

/** jsdom has no dialog top layer. Stub only its native open/close API; focus needs a browser check. */
export function installNativeDialogStub(): void {
  const prototype = HTMLDialogElement.prototype;
  const show = Object.getOwnPropertyDescriptor(prototype, 'showModal');
  const close = Object.getOwnPropertyDescriptor(prototype, 'close');
  beforeAll(() => {
    Object.defineProperty(prototype, 'showModal', { configurable: true, value(this: HTMLDialogElement) { this.open = true; } });
    Object.defineProperty(prototype, 'close', { configurable: true, value(this: HTMLDialogElement) { this.open = false; } });
  });
  afterAll(() => {
    if (show) Object.defineProperty(prototype, 'showModal', show); else Reflect.deleteProperty(prototype, 'showModal');
    if (close) Object.defineProperty(prototype, 'close', close); else Reflect.deleteProperty(prototype, 'close');
  });
}

import { describe, expect, it } from 'vitest';
import { calculateJumps } from './jump';
describe('2014 jumps', () => {
  it('calculates strength 20', () => expect(calculateJumps(20, 5)).toEqual({ longRunning: 6, longStanding: 3, highRunning: 2.4, highStanding: 1.2 }));
  it('calculates strength 10', () => expect(calculateJumps(10, 0)).toEqual({ longRunning: 3, longStanding: 1.5, highRunning: .9, highStanding: .45 }));
  it('clamps negative heights', () => expect(calculateJumps(2, -4)?.highRunning).toBe(0));
  it('preserves unknown values', () => expect(calculateJumps(undefined, 0)).toBeNull());
});

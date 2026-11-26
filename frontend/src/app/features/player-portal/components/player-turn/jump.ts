export function calculateJumps(score: number | null | undefined, modifier: number | null | undefined) {
  if (score == null || modifier == null || !Number.isFinite(score) || !Number.isFinite(modifier)) return null;
  const metres = (feet: number) => Math.round(feet * 30) / 100;
  const high = Math.max(0, 3 + modifier);
  return { longRunning: metres(score), longStanding: metres(score / 2), highRunning: metres(high), highStanding: metres(high / 2) };
}

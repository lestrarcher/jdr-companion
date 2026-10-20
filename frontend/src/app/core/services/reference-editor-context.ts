import { InjectionToken } from '@angular/core';
export type ReferenceEditorScope = 'custom' | 'official';
export const REFERENCE_EDITOR_SCOPE = new InjectionToken<ReferenceEditorScope>('reference-editor-scope', { providedIn: 'root', factory: () => 'custom' });
export const REFERENCE_VIEW_ORIGIN = new InjectionToken<'CUSTOM' | 'OFFICIAL' | null>('reference-view-origin', { providedIn: 'root', factory: () => null });
export const REFERENCE_READ_ONLY = new InjectionToken<boolean>('reference-read-only', { providedIn: 'root', factory: () => false });
export function matchesReferenceOrigin(item: { origin?: string; custom?: boolean }, origin: string | null): boolean {
  return origin === null || (item.origin ?? (item.custom ? 'CUSTOM' : 'OFFICIAL')) === origin;
}

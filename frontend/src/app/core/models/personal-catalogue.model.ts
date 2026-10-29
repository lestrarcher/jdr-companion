export type PersonalCatalogueCategory = 'classes' | 'subclasses' | 'races' | 'feats' | 'progressions';
export interface PersonalCatalogueItem {
  id: number; name: string; slug: string; description: string | null;
  [key: string]: unknown;
}

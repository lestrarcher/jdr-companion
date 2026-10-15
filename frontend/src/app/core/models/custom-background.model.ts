export interface BackgroundRule {
  id: number | null;
  label: string;
  choiceCount: number;
  optionIds: number[];
}
export interface BackgroundPayload {
  name: string;
  slug: string;
  description: string | null;
  equipment: string | null;
  featureName: string | null;
  featureDescription: string | null;
  personalityTraits: string[];
  ideals: string[];
  bonds: string[];
  flaws: string[];
  proficiencyRules: BackgroundRule[];
  languageRules: BackgroundRule[];
}
export interface CustomBackground extends BackgroundPayload {
  id: number;
  origin: 'OFFICIAL' | 'CUSTOM';
  mechanicsLocked: boolean;
}
export interface BackgroundOption { id: number; name: string; slug: string; type: 'SKILL' | 'TOOL' | null }
export interface BackgroundOptions { proficiencies: BackgroundOption[]; languages: BackgroundOption[] }

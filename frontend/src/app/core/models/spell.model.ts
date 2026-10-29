export interface SpellPayload {
  slug: string;
  name: string;
  originalName: string | null;
  level: number;
  school: string;
  castingTime: string;
  range: string;
  duration: string;
  description: string;
  higherLevels: string | null;
  materialDescription: string | null;
  materialCostCp: number | null;
  rulesVariant: string | null;
  sourceCode: string | null;
  sourceReference: string | null;
  sourceUrl: string | null;
  verbal: boolean;
  somatic: boolean;
  material: boolean;
  materialConsumed: boolean;
  concentration: boolean;
  ritual: boolean;
  classIds: number[];
}
export interface Spell extends SpellPayload {
  id: number;
  origin: 'OFFICIAL' | 'CUSTOM';
  edition: string;
  importKey: string | null;
  editable: boolean;
  deletable: boolean;
  classes: { id: number; name: string; origin: 'OFFICIAL' | 'CUSTOM' }[];
}
export interface SpellOptions {
  schools: { value: string; label: string }[];
  classes: { id: number; name: string; origin: 'OFFICIAL' | 'CUSTOM' }[];
}
export interface SpellPage { spells: Spell[]; total: number; page: number; pageSize: number }

export interface SpellGrantPayload {
  quotaImpact?: 'counts-toward-quota' | 'free' | 'unspecified';
  subclassId: number | null;
  mode: string;
  unlockLevel: number;
  castingClassId: number | null;
  slotUsage: string | null;
  restrictions: string | null;
  resourceId: number | null;
  resourceCost: number | null;
}
export interface SpellGrant extends SpellGrantPayload {
  id: number; spellId: number; subclassName: string; activationClassId: number; activationClassName: string;
  castingClassName: string | null; resourceName: string | null;
  origin: 'OFFICIAL' | 'CUSTOM'; editable: boolean;
}
export interface SpellGrantOptions {
  subclasses: { id: number; name: string; origin?: 'OFFICIAL' | 'CUSTOM'; classId: number; className: string }[];
  classes: { id: number; name: string; origin?: 'OFFICIAL' | 'CUSTOM' }[];
  resources: { id: number; name: string; origin?: 'OFFICIAL' | 'CUSTOM' }[];
  modes: { value: string; label: string }[];
}

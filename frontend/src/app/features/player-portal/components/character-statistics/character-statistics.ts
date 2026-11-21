import { calculateInitiative } from './initiative';
import { Component, computed, input, signal } from '@angular/core';
import { CharacterAbilityScore, CharacterArmorClass, CharacterMovement, CharacterFeatSummary, CharacterFeatureSummary, CharacterTraining } from '@core/services/character-api.service';

type Ability = 'strength' | 'dexterity' | 'constitution' | 'intelligence' | 'wisdom' | 'charisma';
type Skill = { slug: string; name: string; ability: Ability };
type Stat = Skill & { bonus: number; proficient: boolean; expertise: boolean };
type Ordering = 'alphabetical' | 'ability';

const ABILITIES: Ability[] = ['strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma'];
const NAMES: Record<Ability, string> = {
  strength: 'Force', dexterity: 'Dextérité', constitution: 'Constitution',
  intelligence: 'Intelligence', wisdom: 'Sagesse', charisma: 'Charisme',
};
const SKILLS: Skill[] = [
  { slug: 'acrobatics', name: 'Acrobaties', ability: 'dexterity' },
  { slug: 'animal-handling', name: 'Dressage', ability: 'wisdom' },
  { slug: 'arcana', name: 'Arcanes', ability: 'intelligence' },
  { slug: 'athletics', name: 'Athlétisme', ability: 'strength' },
  { slug: 'deception', name: 'Tromperie', ability: 'charisma' },
  { slug: 'history', name: 'Histoire', ability: 'intelligence' },
  { slug: 'insight', name: 'Intuition', ability: 'wisdom' },
  { slug: 'intimidation', name: 'Intimidation', ability: 'charisma' },
  { slug: 'investigation', name: 'Investigation', ability: 'intelligence' },
  { slug: 'medicine', name: 'Médecine', ability: 'wisdom' },
  { slug: 'nature', name: 'Nature', ability: 'intelligence' },
  { slug: 'perception', name: 'Perception', ability: 'wisdom' },
  { slug: 'performance', name: 'Représentation', ability: 'charisma' },
  { slug: 'persuasion', name: 'Persuasion', ability: 'charisma' },
  { slug: 'religion', name: 'Religion', ability: 'intelligence' },
  { slug: 'sleight-of-hand', name: 'Escamotage', ability: 'dexterity' },
  { slug: 'stealth', name: 'Discrétion', ability: 'dexterity' },
  { slug: 'survival', name: 'Survie', ability: 'wisdom' },
];
const SAVES: Skill[] = ABILITIES.map(ability => ({
  slug: `saving-throw-${ability}`, name: NAMES[ability], ability,
}));
const compare = (a: Stat, b: Stat) => a.name.localeCompare(b.name, 'fr');
const STORAGE_KEY = 'jdr-companion-character-statistics-ordering';

function initialOrdering(): Ordering {
  try {
    if (typeof localStorage !== 'undefined') {
      return localStorage.getItem(STORAGE_KEY) === 'ability' ? 'ability' : 'alphabetical';
    }
  } catch { /* Storage may be disabled in private browsing. */ }
  return 'alphabetical';
}

@Component({
  selector: 'app-character-statistics',
  standalone: true,
  templateUrl: './character-statistics.html',
  styleUrl: './character-statistics.scss',
})
export class CharacterStatistics {
  armorContributionLabel(part: NonNullable<CharacterArmorClass['contributions']>[number]): string {
    if (part.kind === 'ability') return 'Bonus ' + part.label.replace(' retenue', '');
    if (part.kind === 'base' && part.source === 'Sans armure') return 'Base';
    return part.source || part.label;
  }

  readonly armorClass = input<CharacterArmorClass | null>(null);
  readonly movement = input<CharacterMovement | null>(null);
  readonly movementModes = computed(() => {
    const movement = this.movement();
    if (!movement) return [];
    const labels: Record<string, string> = { swim: 'Nage', fly: 'Vol', climb: 'Escalade', burrow: 'Fouissement' };
    return Object.entries(movement.movementSpeeds ?? {})
      .filter((entry): entry is [string, number] => typeof entry[1] === 'number' && entry[1] > 0)
      .map(([mode, speed]) => ({ mode, label: labels[mode] ?? mode, speed }));
  });
  readonly abilities = input.required<CharacterAbilityScore[]>();
  readonly training = input<CharacterTraining | null>(null);
  readonly beastProficiencies = input<unknown>(null);
  readonly feats = input<CharacterFeatSummary[]>([]);
  readonly features = input<CharacterFeatureSummary[]>([]);
  readonly proficiencyBonus = input(0);
  readonly mode = input<'summary' | 'detail'>('detail');
  readonly ordering = signal<Ordering>(initialOrdering());

  readonly initiative = computed(() => calculateInitiative(
    Object.fromEntries(this.abilities().map(ability => [ability.ability, ability.modifier])),
    this.proficiencyBonus(),
    this.feats().map(feat => feat.slug),
    this.features().map(feature => feature.slug),
  ));
  readonly basePassivePerception = computed(() => {
    if (!this.abilities().some(a => a.ability === 'wisdom')) return null;
    const perception = this.skills().find(skill => skill.slug === 'perception');
    return perception ? 10 + perception.bonus + (this.feats().some(feat => feat.slug === 'observant') ? 5 : 0) : null;
  });
  readonly orderedAbilities = computed(() => ABILITIES
    .map(key => this.abilities().find(score => score.ability === key))
    .filter((score): score is CharacterAbilityScore => !!score));
  readonly saves = computed(() => this.build(SAVES, 'SAVING_THROW'));
  readonly skills = computed(() => this.build(SKILLS, 'SKILL'));
  readonly trainedSaves = computed(() => this.saves().filter(stat => stat.proficient).sort(compare));
  readonly trainedSkills = computed(() => this.skills().filter(stat => stat.proficient).sort(compare));
  readonly alphabeticalSaves = computed(() => [...this.saves()].sort(compare));
  readonly alphabeticalSkills = computed(() => [...this.skills()].sort(compare));
  readonly otherTraining = computed(() => {
    const proficiencies = this.training()?.proficiencies ?? [];
    const categories = [
      { type: 'WEAPON', title: 'Armes', icon: '⚔' },
      { type: 'ARMOR', title: 'Armures', icon: '⬡' },
      { type: 'TOOL', title: 'Outils', icon: '⚒' },
    ];
    return [
      {
        title: 'Armes', icon: '⚔',
        names: proficiencies.filter(p => p.type === 'WEAPON' || p.type === 'WEAPON_GROUP')
          .map(p => p.name).sort((a, b) => a.localeCompare(b, 'fr')),
      },
      ...categories.slice(1).map(category => ({
        title: category.title, icon: category.icon,
        names: proficiencies.filter(p => p.type === category.type)
          .map(p => p.name).sort((a, b) => a.localeCompare(b, 'fr')),
      })),
      {
        title: 'Langues', icon: '✦',
        names: (this.training()?.languages ?? []).map(l => l.name)
          .sort((a, b) => a.localeCompare(b, 'fr')),
      },
    ];
  });
  readonly groups = computed(() => ABILITIES.map(ability => ({
    ability,
    score: this.abilities().find(item => item.ability === ability),
    name: NAMES[ability],
    save: this.saves().find(item => item.ability === ability)!,
    skills: this.skills().filter(item => item.ability === ability).sort(compare),
  })));

  format(value: number): string { return value >= 0 ? `+${value}` : String(value); }

  setOrdering(ordering: Ordering): void {
    this.ordering.set(ordering);
    try {
      if (typeof localStorage !== 'undefined') localStorage.setItem(STORAGE_KEY, ordering);
    } catch { /* Browsers may deny persistent storage. */ }
  }

  private beastBonus(definition: Skill, type: 'SKILL' | 'SAVING_THROW'): number | null {
    const raw = this.beastProficiencies();
    if (!raw) return null;
    const aliases: Record<string, string[]> = {
      'animal-handling': ['animal handling', 'dressage'],
      'sleight-of-hand': ['sleight of hand', 'escamotage'],
      'stealth': ['discretion', 'stealth'],
      'saving-throw-strength': ['str save', 'strength save', 'str saving throw'],
      'saving-throw-dexterity': ['dex save', 'dexterity save', 'dex saving throw'],
      'saving-throw-constitution': ['con save', 'constitution save', 'con saving throw'],
      'saving-throw-intelligence': ['int save', 'intelligence save', 'int saving throw'],
      'saving-throw-wisdom': ['wis save', 'wisdom save', 'wis saving throw'],
      'saving-throw-charisma': ['cha save', 'charisma save', 'cha saving throw'],
    };
    const normalize = (value: string): string => value.toLowerCase().normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '').replace(/[_-]/g, ' ').replace(/\s+/g, ' ').trim();
    const names = [definition.slug, definition.name, ...(aliases[definition.slug] ?? [])].map(normalize);
    const entries: Array<[string, unknown]> = [];
    const collect = (source: unknown): void => {
      if (Array.isArray(source)) {
        for (const entry of source) {
          if (typeof entry === 'string') {
            // Imported SRD entries may look like "Stealth +4".
            const match = entry.trim().match(/^(.+?)\s*([+-]\d+)$/);
            if (match) entries.push([match[1], match[2]]);
          } else if (entry && typeof entry === 'object') {
            const item = entry as Record<string, unknown>;
            const proficiency = item['proficiency'];
            const proficiencyIndex = proficiency && typeof proficiency === 'object'
              ? (proficiency as Record<string, unknown>)['index'] : null;
            const key = proficiencyIndex ?? item['slug'] ?? item['name'] ?? item['skill'] ?? item['ability'];
            if (typeof key === 'string') entries.push([key, item['bonus'] ?? item['modifier'] ?? item['value']]);
          }
        }
      } else if (source && typeof source === 'object') {
        const object = source as Record<string, unknown>;
        for (const [key, value] of Object.entries(object)) {
          if (['skills', 'skill', 'savingThrows', 'saving_throws', 'saves', 'proficiencies'].includes(key)) collect(value);
          else entries.push([key, value]);
        }
      }
    };
    collect(raw);
    for (const [key, value] of entries) {
      // SRD 2014 stores names as `skill-stealth` / `saving-throw-strength`.
      const normalized = normalize(key.replace(/^skill-/, ''));
      if (!names.includes(normalized)) continue;
      const numeric = typeof value === 'number' ? value :
        typeof value === 'string' && /^[+-]?\d+$/.test(value.trim()) ? Number(value) : null;
      if (numeric !== null && Number.isFinite(numeric)) return numeric;
    }
    return null;
  }

  private build(definitions: Skill[], type: 'SKILL' | 'SAVING_THROW'): Stat[] {
    const proficiencies = this.training()?.proficiencies ?? [];
    const expertIds = new Set((this.training()?.expertises ?? []).map(expertise => expertise.proficiencyId));
    return definitions.map(definition => {
      const proficiency = proficiencies.find(item => item.type === type && item.slug === definition.slug);
      const expertise = !!proficiency && expertIds.has(proficiency.id);
      const modifier = this.abilities().find(item => item.ability === definition.ability)?.modifier ?? 0;
      const characterBonus = modifier + (proficiency ? this.proficiencyBonus() * (expertise ? 2 : 1) : 0);
      const beastBonus = this.beastBonus(definition, type);
      // Wild Shape (2014): retain both sets of proficiencies and use the higher
      // modifier when both the character and beast are proficient.
      const useBeast = beastBonus !== null && beastBonus > characterBonus;
      return {
        ...definition,
        proficient: !!proficiency || beastBonus !== null,
        expertise: expertise && !useBeast,
        bonus: useBeast ? beastBonus : characterBonus,
      };
    });
  }
}

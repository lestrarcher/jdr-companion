import { Component, computed, input, signal } from '@angular/core';
import { CharacterAbilityScore, CharacterTraining } from '@core/services/character-api.service';

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
  readonly abilities = input.required<CharacterAbilityScore[]>();
  readonly training = input<CharacterTraining | null>(null);
  readonly proficiencyBonus = input(0);
  readonly mode = input<'summary' | 'detail'>('detail');
  readonly ordering = signal<Ordering>(initialOrdering());

  // Base values only: equipment, features and other modifiers are not yet exposed.
  readonly baseInitiative = computed(() => this.abilities().find(a => a.ability === 'dexterity')?.modifier ?? null);
  readonly basePassivePerception = computed(() => {
    if (!this.abilities().some(a => a.ability === 'wisdom')) return null;
    const perception = this.skills().find(skill => skill.slug === 'perception');
    return perception ? 10 + perception.bonus : null;
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

  private build(definitions: Skill[], type: 'SKILL' | 'SAVING_THROW'): Stat[] {
    const proficiencies = this.training()?.proficiencies ?? [];
    const expertIds = new Set((this.training()?.expertises ?? []).map(expertise => expertise.proficiencyId));
    return definitions.map(definition => {
      const proficiency = proficiencies.find(item => item.type === type && item.slug === definition.slug);
      const expertise = !!proficiency && expertIds.has(proficiency.id);
      const modifier = this.abilities().find(item => item.ability === definition.ability)?.modifier ?? 0;
      return {
        ...definition,
        proficient: !!proficiency,
        expertise,
        bonus: modifier + (proficiency ? this.proficiencyBonus() * (expertise ? 2 : 1) : 0),
      };
    });
  }
}

import type { CharacterAbilityScore, CharacterTraining } from '@core/services/character-api.service';
type Ability = 'strength' | 'dexterity' | 'constitution' | 'intelligence' | 'wisdom' | 'charisma';
type Skill = { slug: string; name: string; ability: Ability };
type Stat = Skill & { bonus: number; proficient: boolean; expertise: boolean };
export function getBeastBonus(raw: unknown, definition: Skill, type: 'SKILL' | 'SAVING_THROW'): number | null {
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

export function buildStats(definitions: Skill[], type: 'SKILL' | 'SAVING_THROW', abilities: CharacterAbilityScore[], training: CharacterTraining | null, proficiencyBonus: number, beastProficiencies: unknown): Stat[] {
    const proficiencies = training?.proficiencies ?? [];
    const expertIds = new Set((training?.expertises ?? []).map(expertise => expertise.proficiencyId));
    return definitions.map(definition => {
      const proficiency = proficiencies.find(item => item.type === type && item.slug === definition.slug);
      const expertise = !!proficiency && expertIds.has(proficiency.id);
      const modifier = abilities.find(item => item.ability === definition.ability)?.modifier ?? 0;
      const characterBonus = modifier + (proficiency ? proficiencyBonus * (expertise ? 2 : 1) : 0);
      const beastBonus = getBeastBonus(beastProficiencies, definition, type);
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

export function athleticsBonus(abilities: CharacterAbilityScore[], training: CharacterTraining | null, proficiencyBonus: number, beastProficiencies: unknown): number | null {
  if (!abilities.some(a => a.ability === 'strength')) return null;
  return buildStats([{ slug: 'athletics', name: 'Athlétisme', ability: 'strength' }], 'SKILL', abilities, training, proficiencyBonus, beastProficiencies)[0].bonus;
}

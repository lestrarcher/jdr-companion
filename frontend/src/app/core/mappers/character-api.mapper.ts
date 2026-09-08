import { Character } from '@core/models/character.model';

export interface CharacterSessionStatePayload {
  hitPoints: {
    current: number;
    temporary: number;
  };

  hitDice: Array<{
    id: string;
    current: number;
  }>;

  progressions: Array<{
    id: string;
    currentValue: number;
  }>;

  resources: Array<{
    id: string;
    currentValue: number;
    notes?: string;
    storedValues?: number[];
  }>;
}

export interface CharacterProfilePayload {
  slug: string;
  name: string;
  type: 'player' | 'npc';
  totalLevel: number;

  hitPoints: {
    maximumValue: number | null;
  };

  classSummary: Array<{
    className: string;
    level: number;
    hitDie: number;
    subclassName: string | null;
  }>;

  resources: Array<{
    slug: string;
    name: string;
    maximum: number;
    rechargeType: string;
  }>;

  definition?: {
    portraitUrl?: string | null;
    progressions?: Array<{
      id: string;
      name: string;
      minimumValue: number;
      maximumValue?: number;
    }>;
    resources?: Array<{
      id: string;
      name: string;
      shortName?: string;
      category?: string;
      resetPeriod?: string;
      notesEditable?: boolean;
      allowManualIncrease?: boolean;
      maximumValue?: number;
      level?: number;
      displayOrder?: number;
      unlockCondition?: {
        progressionId: string;
        minimumValue: number;
      };
      storedValuesConfig?: {
        requiredCount: number;
        minimumValue: number;
        maximumValue: number;
      };
    }>;
  };
}

export function toCharacterSessionStatePayload(
  character: Character,
): CharacterSessionStatePayload {
  return {
    hitPoints: {
      current: character.hitPoints.current,
      temporary: character.hitPoints.temporary,
    },

    hitDice: character.hitDice.map((pool) => ({
      id: pool.id,
      current: pool.current,
    })),

    progressions: (character.progressions ?? []).map((progression) => ({
      id: progression.id,
      currentValue: progression.currentValue,
    })),

    resources: character.resources.map((resource) => ({
      id: resource.id,
      currentValue: resource.currentValue,
      ...(resource.notes !== undefined
        ? { notes: resource.notes }
        : {}),
      ...(resource.storedValues !== undefined
        ? { storedValues: resource.storedValues }
        : {}),
    })),
  };
}

export function characterProfileToCharacter(
  profile: CharacterProfilePayload,
  state: CharacterSessionStatePayload,
): Character {
  const definition = profile.definition ?? {};
  const legacyResources = definition.resources ?? [];

  const className = profile.classSummary
    .map((entry) => {
      const subclass = entry.subclassName
        ? ` — ${entry.subclassName}`
        : '';

      return `${entry.className} ${entry.level}${subclass}`;
    })
    .join(' / ');

  const hitDice = profile.classSummary.map((entry) => {
    const id = `d${entry.hitDie}`;
    const storedPool = state.hitDice.find(
      (candidate) => candidate.id === id,
    );

    return {
      id,
      die: id as Character['hitDice'][number]['die'],
      current: storedPool?.current ?? entry.level,
      maximum: entry.level,
    };
  });

  const progressions = (definition.progressions ?? []).map(
    (progression) => {
      const storedProgression = state.progressions.find(
        (candidate) => candidate.id === progression.id,
      );

      return {
        ...progression,
        currentValue:
          storedProgression?.currentValue ??
          progression.minimumValue,
      };
    },
  );

  const resources = profile.resources.map((resource, index) => {
    const legacyResource = legacyResources.find(
      (candidate) => candidate.id === resource.slug,
    );

    const storedResource = state.resources.find(
      (candidate) => candidate.id === resource.slug,
    );

    return {
      id: resource.slug,
      name: resource.name,
      shortName: legacyResource?.shortName,
      category:
        (legacyResource?.category ??
          'class-feature') as Character['resources'][number]['category'],
      resetPeriod:
        (legacyResource?.resetPeriod ??
          resource.rechargeType) as Character['resources'][number]['resetPeriod'],
      notes: storedResource?.notes,
      notesEditable: legacyResource?.notesEditable,
      allowManualIncrease:
        legacyResource?.allowManualIncrease,
      currentValue:
        storedResource?.currentValue ??
        resource.maximum,
      maximumValue: resource.maximum,
      level: legacyResource?.level,
      displayOrder:
        legacyResource?.displayOrder ?? index,
      unlockCondition:
        legacyResource?.unlockCondition,
      storedValues:
        storedResource?.storedValues,
      storedValuesConfig:
        legacyResource?.storedValuesConfig,
    };
  });

  return {
    id: `character-${profile.slug}`,
    name: profile.name,
    type: profile.type === 'player' ? 'pc' : 'npc',

    className,
    level: profile.totalLevel,

    portraitUrl: definition.portraitUrl ?? undefined,

    hitPoints: {
      maximum: profile.hitPoints.maximumValue ?? 0,
      current: state.hitPoints.current,
      temporary: state.hitPoints.temporary,
    },

    hitDice,
    progressions,
    resources,
  };
}

export function applyCharacterSessionState(
  character: Character,
  state: CharacterSessionStatePayload,
): Character {
  return {
    ...character,

    hitPoints: {
      ...character.hitPoints,
      current: state.hitPoints.current,
      temporary: state.hitPoints.temporary,
    },

    hitDice: character.hitDice.map((pool) => {
      const storedPool = state.hitDice.find(
        (candidate) => candidate.id === pool.id,
      );

      return {
        ...pool,
        current: storedPool?.current ?? pool.current,
      };
    }),

    progressions: (character.progressions ?? []).map(
      (progression) => {
        const storedProgression = state.progressions.find(
          (candidate) => candidate.id === progression.id,
        );

        return {
          ...progression,
          currentValue:
            storedProgression?.currentValue ??
            progression.currentValue,
        };
      },
    ),

    resources: character.resources.map((resource) => {
      const storedResource = state.resources.find(
        (candidate) => candidate.id === resource.id,
      );

      if (!storedResource) {
        return resource;
      }

      return {
        ...resource,
        currentValue: storedResource.currentValue,

        notes:
          storedResource.notes !== undefined
            ? storedResource.notes
            : resource.notes,

        storedValues:
          storedResource.storedValues !== undefined
            ? [...storedResource.storedValues]
            : resource.storedValues,
      };
    }),
  };
}

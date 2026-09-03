import { CampaignConfig } from '@core/models/campaign.model';
import { Character } from '@core/models/character.model';
import { CreateCharacterPayload } from '@core/services/character-api.service';

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

export function toCreateCharacterPayload(
  character: Character,
  campaign: CampaignConfig,
): CreateCharacterPayload {
  const player = campaign.players.find(
    (candidate) => candidate.characterId === character.id,
  );

  return {
    slug: character.id.replace(/^character-/, ''),
    name: character.name,
    playerName: player?.displayName ?? null,
    type: character.type === 'pc' ? 'player' : 'npc',

    definition: {
      className: character.className,
      level: character.level,
      portraitUrl: character.portraitUrl ?? null,

      hitPoints: {
        maximum: character.hitPoints.maximum,
      },

      hitDice: character.hitDice.map((pool) => ({
        id: pool.id,
        die: pool.die,
        maximum: pool.maximum,
      })),

      progressions: (character.progressions ?? []).map((progression) => ({
        id: progression.id,
        name: progression.name,
        minimumValue: progression.minimumValue,
        maximumValue: progression.maximumValue,
      })),

      resources: character.resources.map((resource) => ({
        id: resource.id,
        name: resource.name,
        shortName: resource.shortName,
        category: resource.category,
        resetPeriod: resource.resetPeriod,
        notesEditable: resource.notesEditable,
        allowManualIncrease: resource.allowManualIncrease,
        maximumValue: resource.maximumValue,
        level: resource.level,
        displayOrder: resource.displayOrder,
        unlockCondition: resource.unlockCondition,
        storedValuesConfig: resource.storedValuesConfig,
      })),
    },
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

import { Injectable, signal } from '@angular/core';

import { Character } from '@core/models/character.model';

@Injectable({
  providedIn: 'root',
})
export class CharacterStateService {
  private readonly currentCharacter =
    signal<Character | null>(null);

  readonly character = this.currentCharacter.asReadonly();

  private storageKey = '';
  private channel?: BroadcastChannel;

  initialize(campaignId: string, character: Character): void {
    this.channel?.close();

    this.storageKey =
      `jdr-companion:${campaignId}:characters:${character.id}:state`;

    const initialState = this.cloneCharacter(character);
    const storedState = this.loadStoredState();

    this.currentCharacter.set(
      storedState
        ? this.mergeCharacterState(initialState, storedState)
        : initialState,
    );

    if (typeof BroadcastChannel === 'undefined') {
      return;
    }

    this.channel = new BroadcastChannel(this.storageKey);

    this.channel.onmessage = (
      event: MessageEvent<Character>,
    ): void => {
      this.currentCharacter.set(event.data);
      this.saveLocally(event.data);
    };
  }

  applyDamage(amount: number): void {
    const character = this.currentCharacter();
    const damage = this.normalizeAmount(amount);

    if (!character || damage === 0) {
      return;
    }

    const absorbedDamage = Math.min(
      character.hitPoints.temporary,
      damage,
    );

    const remainingDamage = damage - absorbedDamage;

    this.updateCharacter({
      ...character,

      hitPoints: {
        ...character.hitPoints,

        temporary:
          character.hitPoints.temporary - absorbedDamage,

        current: Math.max(
          0,
          character.hitPoints.current - remainingDamage,
        ),
      },
    });
  }

  heal(amount: number): void {
    const character = this.currentCharacter();
    const healing = this.normalizeAmount(amount);

    if (!character || healing === 0) {
      return;
    }

    this.updateCharacter({
      ...character,

      hitPoints: {
        ...character.hitPoints,

        current: Math.min(
          character.hitPoints.maximum,
          character.hitPoints.current + healing,
        ),
      },
    });
  }

  adjustTemporaryHitPoints(change: number): void {
    const character = this.currentCharacter();

    if (!character) {
      return;
    }

    this.updateCharacter({
      ...character,

      hitPoints: {
        ...character.hitPoints,

        temporary: Math.max(
          0,
          character.hitPoints.temporary + change,
        ),
      },
    });
  }

  adjustResource(resourceId: string, change: number): void {
    const character = this.currentCharacter();

    if (!character) {
      return;
    }

    const resources = character.resources.map((resource) => {
      if (resource.id !== resourceId) {
        return resource;
      }

      /*
       * Seules les ressources manuelles peuvent être
       * restaurées directement depuis l’interface joueur.
       */
      if (change > 0 && resource.resetPeriod !== 'manual') {
        return resource;
      }

      return {
        ...resource,

        currentValue: Math.min(
          resource.maximumValue,
          Math.max(0, resource.currentValue + change),
        ),
      };
    });

    this.updateCharacter({
      ...character,
      resources,
    });
  }

  adjustHitDice(
    hitDicePoolId: string,
    change: number,
  ): void {
    const character = this.currentCharacter();

    if (!character) {
      return;
    }

    /*
     * Le joueur peut dépenser un dé de vie, mais pas
     * le récupérer manuellement : cela passe par le repos long.
     */
    if (change > 0) {
      return;
    }

    const hitDice = character.hitDice.map((pool) => {
      if (pool.id !== hitDicePoolId) {
        return pool;
      }

      return {
        ...pool,

        current: Math.max(
          0,
          pool.current + change,
        ),
      };
    });

    this.updateCharacter({
      ...character,
      hitDice,
    });
  }

  updateResourceNotes(
    resourceId: string,
    notes: string,
  ): void {
    const character = this.currentCharacter();

    if (!character) {
      return;
    }

    const resources = character.resources.map((resource) =>
      resource.id === resourceId
        ? {
            ...resource,
            notes,
          }
        : resource,
    );

    this.updateCharacter({
      ...character,
      resources,
    });
  }

  applyShortRest(): void {
    const character = this.currentCharacter();

    if (!character) {
      return;
    }

    this.updateCharacter({
      ...character,

      resources: character.resources.map((resource) => {
        if (resource.resetPeriod !== 'short-rest') {
          return resource;
        }

        return {
          ...resource,
          currentValue: resource.maximumValue,
        };
      }),
    });
  }

  applyLongRest(): void {
    const character = this.currentCharacter();

    if (!character) {
      return;
    }

    this.updateCharacter({
      ...character,

      hitPoints: {
        ...character.hitPoints,
        current: character.hitPoints.maximum,
        temporary: 0,
      },

      hitDice: character.hitDice.map((pool) => ({
        ...pool,

        current: Math.min(
          pool.maximum,

          pool.current +
            Math.max(
              1,
              Math.floor(pool.maximum / 2),
            ),
        ),
      })),

      resources: character.resources.map((resource) => {
        const resetsOnLongRest =
          resource.resetPeriod === 'short-rest' ||
          resource.resetPeriod === 'long-rest';

        if (!resetsOnLongRest) {
          return resource;
        }

        return {
          ...resource,
          currentValue: resource.maximumValue,
        };
      }),
    });
  }

  private updateCharacter(character: Character): void {
    this.currentCharacter.set(character);
    this.saveLocally(character);
    this.channel?.postMessage(character);
  }

  private loadStoredState(): Character | null {
    if (
      !this.storageKey ||
      typeof localStorage === 'undefined'
    ) {
      return null;
    }

    const storedValue = localStorage.getItem(this.storageKey);

    if (!storedValue) {
      return null;
    }

    try {
      return JSON.parse(storedValue) as Character;
    } catch {
      localStorage.removeItem(this.storageKey);
      return null;
    }
  }

  private saveLocally(character: Character): void {
    if (
      !this.storageKey ||
      typeof localStorage === 'undefined'
    ) {
      return;
    }

    localStorage.setItem(
      this.storageKey,
      JSON.stringify(character),
    );
  }

  private cloneCharacter(character: Character): Character {
    return {
      ...character,

      hitPoints: {
        ...character.hitPoints,
      },

      hitDice: character.hitDice.map((pool) => ({
        ...pool,
      })),

      resources: character.resources.map((resource) => ({
        ...resource,
      })),
    };
  }

  private mergeCharacterState(
    initialState: Character,
    storedState: Character,
  ): Character {
    return {
      ...initialState,
      ...storedState,

      hitPoints: {
        ...initialState.hitPoints,
        ...storedState.hitPoints,
      },

      hitDice:
        storedState.hitDice ?? initialState.hitDice,

      resources: initialState.resources.map(
        (initialResource) => {
          const storedResource =
            storedState.resources?.find(
              (resource) =>
                resource.id === initialResource.id,
            );

          return {
            ...initialResource,
            ...storedResource,
          };
        },
      ),
    };
  }

  private normalizeAmount(amount: number): number {
    if (!Number.isFinite(amount)) {
      return 0;
    }

    return Math.max(0, Math.floor(amount));
  }
}

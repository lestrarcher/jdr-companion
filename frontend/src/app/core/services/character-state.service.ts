import { Injectable, signal } from '@angular/core';

import { Character } from '@core/models/character.model';

@Injectable({
  providedIn: 'root',
})
export class CharacterStateService {
  private readonly currentCharacter =
    signal<Character | null>(null);

  readonly character =
    this.currentCharacter.asReadonly();

  private storageKey = '';
  private channel?: BroadcastChannel;

  initialize(
    campaignId: string,
    character: Character,
    loadLocalState = true,
  ): void {
    this.channel?.close();

    this.storageKey =
      `jdr-companion:${campaignId}:characters:${character.id}:state`;

    const initialState =
      this.cloneCharacter(character);

    const storedState = loadLocalState
      ? this.loadStoredState()
      : null;

    this.currentCharacter.set(
      storedState
        ? this.mergeCharacterState(
            initialState,
            storedState,
          )
        : initialState,
    );

    if (typeof BroadcastChannel === 'undefined') {
      return;
    }

    this.channel =
      new BroadcastChannel(this.storageKey);

    this.channel.onmessage = (
      event: MessageEvent<Character>,
    ): void => {
      const synchronizedCharacter =
        this.cloneCharacter(event.data);

      this.currentCharacter.set(
        synchronizedCharacter,
      );

      this.saveLocally(
        synchronizedCharacter,
      );
    };
  }

  applyDamage(amount: number): void {
    const character =
      this.currentCharacter();

    const damage =
      this.normalizeAmount(amount);

    if (!character || damage === 0) {
      return;
    }

    const absorbedDamage = Math.min(
      character.hitPoints.temporary,
      damage,
    );

    const remainingDamage =
      damage - absorbedDamage;

    this.updateCharacter({
      ...character,

      hitPoints: {
        ...character.hitPoints,

        temporary:
          character.hitPoints.temporary -
          absorbedDamage,

        current: Math.max(
          0,
          character.hitPoints.current -
            remainingDamage,
        ),
      },
    });
  }

  heal(amount: number): void {
    const character =
      this.currentCharacter();

    const healing =
      this.normalizeAmount(amount);

    if (!character || healing === 0) {
      return;
    }

    this.updateCharacter({
      ...character,

      hitPoints: {
        ...character.hitPoints,

        current: Math.min(
          character.hitPoints.maximum,
          character.hitPoints.current +
            healing,
        ),
      },
    });
  }

  adjustTemporaryHitPoints(
    change: number,
  ): void {
    const character =
      this.currentCharacter();

    if (!character) {
      return;
    }

    this.updateCharacter({
      ...character,

      hitPoints: {
        ...character.hitPoints,

        temporary: Math.max(
          0,
          character.hitPoints.temporary +
            change,
        ),
      },
    });
  }

  adjustResource(
    resourceId: string,
    change: number,
  ): void {
    const character =
      this.currentCharacter();

    if (!character) {
      return;
    }

    const resources =
      character.resources.map(
        (resource) => {
          if (resource.id !== resourceId) {
            return resource;
          }

          /*
           * Les ressources à valeurs stockées,
           * comme Présage, sont gérées par leur
           * interface dédiée.
           */
          if (resource.storedValuesConfig) {
            return resource;
          }

          const canIncreaseManually =
            resource.resetPeriod === 'manual' ||
            resource.allowManualIncrease === true;

          if (
            change > 0 &&
            !canIncreaseManually
          ) {
            return resource;
          }

          return {
            ...resource,

            currentValue: Math.min(
              resource.maximumValue,
              Math.max(
                0,
                resource.currentValue +
                  change,
              ),
            ),
          };
        },
      );

    this.updateCharacter({
      ...character,
      resources,
    });
  }

  setStoredValues(
    resourceId: string,
    values: number[],
  ): void {
    const character =
      this.currentCharacter();

    if (!character) {
      return;
    }

    const resources =
      character.resources.map(
        (resource) => {
          if (
            resource.id !== resourceId ||
            !resource.storedValuesConfig
          ) {
            return resource;
          }

          /*
           * Une ressource déjà initialisée ne
           * peut pas être relancée manuellement.
           *
           * [] signifie que tous les résultats
           * ont déjà été consommés.
           */
          if (
            resource.storedValues !==
            undefined
          ) {
            return resource;
          }

          const config =
            resource.storedValuesConfig;

          const normalizedValues =
            values
              .map((value) =>
                Math.floor(Number(value)),
              )
              .filter(
                (value) =>
                  Number.isFinite(value) &&
                  value >=
                    config.minimumValue &&
                  value <=
                    config.maximumValue,
              )
              .slice(
                0,
                config.requiredCount,
              );

          if (
            normalizedValues.length !==
            config.requiredCount
          ) {
            return resource;
          }

          return {
            ...resource,

            storedValues:
              normalizedValues,

            currentValue:
              normalizedValues.length,
          };
        },
      );

    this.updateCharacter({
      ...character,
      resources,
    });
  }

  consumeStoredValue(
    resourceId: string,
    valueIndex: number,
  ): void {
    const character =
      this.currentCharacter();

    if (!character) {
      return;
    }

    const resources =
      character.resources.map(
        (resource) => {
          if (
            resource.id !== resourceId ||
            !resource.storedValuesConfig ||
            resource.storedValues ===
              undefined
          ) {
            return resource;
          }

          if (
            valueIndex < 0 ||
            valueIndex >=
              resource.storedValues.length
          ) {
            return resource;
          }

          const storedValues =
            resource.storedValues.filter(
              (_, index) =>
                index !== valueIndex,
            );

          return {
            ...resource,
            storedValues,
            currentValue:
              storedValues.length,
          };
        },
      );

    this.updateCharacter({
      ...character,
      resources,
    });
  }

  adjustHitDice(
    hitDicePoolId: string,
    change: number,
  ): void {
    const character =
      this.currentCharacter();

    if (!character) {
      return;
    }

    /*
     * Le joueur peut dépenser ses dés de vie,
     * mais leur récupération passe par le repos.
     */
    if (change > 0) {
      return;
    }

    const hitDice =
      character.hitDice.map((pool) => {
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
    const character =
      this.currentCharacter();

    if (!character) {
      return;
    }

    const resources =
      character.resources.map(
        (resource) =>
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

  adjustProgression(
    progressionId: string,
    change: number,
  ): void {
    const character =
      this.currentCharacter();

    if (!character) {
      return;
    }

    const progressions =
      (
        character.progressions ?? []
      ).map((progression) => {
        if (
          progression.id !==
          progressionId
        ) {
          return progression;
        }

        const nextValue = Math.max(
          progression.minimumValue,
          progression.currentValue +
            change,
        );

        return {
          ...progression,

          currentValue:
            progression.maximumValue ===
            undefined
              ? nextValue
              : Math.min(
                  progression.maximumValue,
                  nextValue,
                ),
        };
      });

    this.updateCharacter({
      ...character,
      progressions,
    });
  }

  applyShortRest(): void {
    const character =
      this.currentCharacter();

    if (!character) {
      return;
    }

    this.updateCharacter({
      ...character,

      resources:
        character.resources.map(
          (resource) => {
            if (
              resource.resetPeriod !==
              'short-rest'
            ) {
              return resource;
            }

            return {
              ...resource,
              currentValue:
                resource.maximumValue,
            };
          },
        ),
    });
  }

  applyLongRest(): void {
    const character =
      this.currentCharacter();

    if (!character) {
      return;
    }

    this.updateCharacter({
      ...character,

      hitPoints: {
        ...character.hitPoints,

        current:
          character.hitPoints.maximum,

        temporary: 0,
      },

      hitDice:
        character.hitDice.map(
          (pool) => ({
            ...pool,

            current: Math.min(
              pool.maximum,

              pool.current +
                Math.max(
                  1,
                  Math.floor(
                    pool.maximum / 2,
                  ),
                ),
            ),
          }),
        ),

      resources:
        character.resources.map(
          (resource) => {
            const resetsOnLongRest =
              resource.resetPeriod ===
                'short-rest' ||
              resource.resetPeriod ===
                'long-rest';

            if (!resetsOnLongRest) {
              return resource;
            }

            /*
             * undefined signifie que de nouveaux
             * résultats doivent être saisis.
             *
             * Un tableau vide signifie que tous
             * les résultats ont été consommés.
             */
            if (
              resource.storedValuesConfig
            ) {
              return {
                ...resource,

                storedValues: undefined,

                currentValue: 0,
              };
            }

            return {
              ...resource,

              currentValue:
                resource.maximumValue,
            };
          },
        ),
    });
  }

  private updateCharacter(
    character: Character,
  ): void {
    const nextCharacter =
      this.cloneCharacter(character);

    this.currentCharacter.set(
      nextCharacter,
    );

    this.saveLocally(
      nextCharacter,
    );

    this.channel?.postMessage(
      nextCharacter,
    );
  }

  private loadStoredState():
    Character | null {
    if (
      !this.storageKey ||
      typeof localStorage === 'undefined'
    ) {
      return null;
    }

    const storedValue =
      localStorage.getItem(
        this.storageKey,
      );

    if (!storedValue) {
      return null;
    }

    try {
      return JSON.parse(
        storedValue,
      ) as Character;
    } catch {
      localStorage.removeItem(
        this.storageKey,
      );

      return null;
    }
  }

  private saveLocally(
    character: Character,
  ): void {
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

  private cloneCharacter(
    character: Character,
  ): Character {
    return {
      ...character,

      hitPoints: {
        ...character.hitPoints,
      },

      hitDice:
        character.hitDice.map(
          (pool) => ({
            ...pool,
          }),
        ),

      progressions:
        (
          character.progressions ?? []
        ).map((progression) => ({
          ...progression,
        })),

      resources:
        character.resources.map(
          (resource) => ({
            ...resource,

            unlockCondition:
              resource.unlockCondition
                ? {
                    ...resource.unlockCondition,
                  }
                : undefined,

            storedValuesConfig:
              resource.storedValuesConfig
                ? {
                    ...resource.storedValuesConfig,
                  }
                : undefined,

            /*
             * Attention à ne pas utiliser seulement
             * un test de longueur : [] représente
             * bien l’état "tous consommés".
             */
            storedValues:
              resource.storedValues ===
              undefined
                ? undefined
                : [
                    ...resource.storedValues,
                  ],
          }),
        ),
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
        storedState.hitDice ??
        initialState.hitDice,

      /*
       * La définition vient du code.
       * Seule la valeur courante vient
       * du stockage.
       */
      progressions:
        (
          initialState.progressions ?? []
        ).map(
          (initialProgression) => {
            const storedProgression =
              storedState.progressions?.find(
                (progression) =>
                  progression.id ===
                  initialProgression.id,
              );

            return {
              ...initialProgression,

              currentValue:
                storedProgression
                  ?.currentValue ??
                initialProgression
                  .currentValue,
            };
          },
        ),

      resources:
        initialState.resources.map(
          (initialResource) => {
            const storedResource =
              storedState.resources?.find(
                (resource) =>
                  resource.id ===
                  initialResource.id,
              );

            /*
             * On vérifie explicitement undefined :
             * [] est une valeur valide et signifie
             * que tous les présages sont utilisés.
             */
            const storedValues =
              storedResource
                ?.storedValues !==
              undefined
                ? [
                    ...storedResource
                      .storedValues,
                  ]
                : initialResource
                      .storedValues !==
                    undefined
                  ? [
                      ...initialResource
                        .storedValues,
                    ]
                  : undefined;

            if (
              initialResource
                .storedValuesConfig
            ) {
              return {
                ...initialResource,
                ...storedResource,

                /*
                 * La configuration vient toujours
                 * de la définition actuelle.
                 */
                storedValuesConfig: {
                  ...initialResource
                    .storedValuesConfig,
                },

                storedValues,

                currentValue:
                  storedValues?.length ??
                  0,
              };
            }

            return {
              ...initialResource,
              ...storedResource,

              storedValuesConfig:
                undefined,

              storedValues:
                undefined,

              currentValue:
                storedResource
                  ?.currentValue ??
                initialResource
                  .currentValue,
            };
          },
        ),
    };
  }

  private normalizeAmount(
    amount: number,
  ): number {
    if (!Number.isFinite(amount)) {
      return 0;
    }

    return Math.max(
      0,
      Math.floor(amount),
    );
  }
}

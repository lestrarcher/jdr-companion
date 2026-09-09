<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Repository\CharacterSessionStateRepository;

final readonly class CharacterSessionStateSynchronizer
{
    public function __construct(
        private CharacterHitPointCalculator $hitPointCalculator,
        private CharacterResourceResolver $resourceResolver,
        private CharacterSessionStateRepository $sessionStateRepository,
        private CharacterSpellSlotCalculator $spellSlotCalculator,
    ) {
    }

    /**
     * Synchronisation simple utilisée notamment avant un repos.
     *
     * Elle ajoute les ressources nouvellement disponibles et conserve
     * les valeurs courantes existantes.
     *
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    public function synchronize(Character $character, array $state): array
    {
        $maximums = $this->resourceMaximums($character);
        $resources = $state['resources'] ?? [];

        foreach ($maximums as $id => $maximum) {
            $index = $this->findStateIndex($resources, $id);

            if ($index === null) {
                $resources[] = [
                    'id' => $id,
                    'currentValue' => $maximum,
                ];

                continue;
            }

            $current = (int) ($resources[$index]['currentValue'] ?? 0);

            $resources[$index]['currentValue'] = min(
                $current,
                $maximum,
            );
        }

        $progressions = $state['progressions'] ?? [];

        foreach ($character->getProgressions() as $characterProgression) {
            $definition =
                $characterProgression->getProgressionDefinition();

            $id = $definition->getSlug();
            $index = $this->findStateIndex($progressions, $id);

            if ($index !== null) {
                continue;
            }

            $progressions[] = [
                'id' => $id,
                'currentValue' => $definition->getMinimumValue(),
            ];
        }

        $state['progressions'] = $progressions;

        $state['resources'] = $resources;

        return $state;
    }

    /**
     * Initialise dans les états de session les progressions
     * attribuées au personnage qui n'existent pas encore.
     *
     * Les valeurs existantes ne sont jamais modifiées.
     */
    public function initializeMissingProgressions(
        Character $character,
    ): void {
        $sessionStates = $this->sessionStateRepository->findBy([
            'character' => $character,
        ]);

        foreach ($sessionStates as $sessionState) {
            $state = $sessionState->getState();
            $progressions = $state['progressions'] ?? [];

            foreach ($character->getProgressions() as $characterProgression) {
                $definition =
                    $characterProgression->getProgressionDefinition();

                $id = $definition->getSlug();

                if ($this->findStateIndex($progressions, $id) !== null) {
                    continue;
                }

                $progressions[] = [
                    'id' => $id,
                    'currentValue' =>
                        $definition->getMinimumValue(),
                ];
            }

            $state['progressions'] = $progressions;
            $sessionState->setState($state);
        }
    }

    /**
     * Photographie les maximums dérivés du personnage avant une modification.
     *
     * @return array{
     *     hitPoints: int|null,
     *     hitDice: array<string, int>,
     *     resources: array<string, int>
     * }
     */
    public function snapshot(Character $character): array
    {
        $hitPoints = $this->hitPointCalculator->calculate($character);

        return [
            'hitPoints' => $hitPoints->isComplete()
                ? $hitPoints->maximumValue
                : null,
            'hitDice' => $this->hitDiceMaximums($character),
            'resources' => $this->resourceMaximums($character),
        ];
    }

    /**
     * Applique aux états de session uniquement ce qui vient d'être gagné
     * grâce au level-up.
     *
     * @param array{
     *     hitPoints: int|null,
     *     hitDice: array<string, int>,
     *     resources: array<string, int>
     * } $before
     */
    public function synchronizeAfterLevelUp(
        Character $character,
        array $before,
    ): void {
        $after = $this->snapshot($character);

        $sessionStates = $this->sessionStateRepository->findBy([
            'character' => $character,
        ]);

        foreach ($sessionStates as $sessionState) {
            $state = $sessionState->getState();

            $state = $this->applyHitPointDelta(
                $state,
                $before['hitPoints'],
                $after['hitPoints'],
            );

            $state['hitDice'] = $this->applyPoolDeltas(
                $state['hitDice'] ?? [],
                $before['hitDice'],
                $after['hitDice'],
                'current',
            );

            $state['resources'] = $this->applyPoolDeltas(
                $state['resources'] ?? [],
                $before['resources'],
                $after['resources'],
                'currentValue',
            );

            $sessionState->setState($state);
        }
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function applyHitPointDelta(
        array $state,
        ?int $beforeMaximum,
        ?int $afterMaximum,
    ): array {
        if ($beforeMaximum === null || $afterMaximum === null) {
            return $state;
        }

        $current = (int) ($state['hitPoints']['current'] ?? 0);
        $delta = $afterMaximum - $beforeMaximum;

        $state['hitPoints']['current'] = $delta >= 0
            ? min($afterMaximum, $current + $delta)
            : min($current, $afterMaximum);

        return $state;
    }

    /**
     * @param array<int, array<string, mixed>> $states
     * @param array<string, int>               $beforeMaximums
     * @param array<string, int>               $afterMaximums
     *
     * @return array<int, array<string, mixed>>
     */
    private function applyPoolDeltas(
        array $states,
        array $beforeMaximums,
        array $afterMaximums,
        string $currentField,
    ): array {
        foreach ($afterMaximums as $id => $afterMaximum) {
            $beforeMaximum = $beforeMaximums[$id] ?? 0;
            $index = $this->findStateIndex($states, $id);

            if ($index === null) {
                $states[] = [
                    'id' => $id,
                    $currentField => $afterMaximum,
                ];

                continue;
            }

            $current = (int) ($states[$index][$currentField] ?? 0);
            $delta = $afterMaximum - $beforeMaximum;

            $states[$index][$currentField] = $delta >= 0
                ? min($afterMaximum, $current + $delta)
                : min($current, $afterMaximum);
        }

        return $states;
    }

    /**
     * @return array<string, int>
     */
    private function hitDiceMaximums(Character $character): array
    {
        $maximums = [];

        foreach ($character->getClassLevels() as $level) {
            $id = sprintf(
                'd%d',
                $level->getCharacterClass()->getHitDie(),
            );

            $maximums[$id] = ($maximums[$id] ?? 0) + 1;
        }

        return $maximums;
    }

    /**
     * @return array<string, int>
     */
    private function resourceMaximums(Character $character): array
    {
        $maximums = [];

        foreach ($this->resourceResolver->resolve($character) as $resource) {
            $maximums[$resource->getSlug()] = $resource->getMaximum();
        }

        foreach ($this->spellSlotCalculator->calculate($character) as $level => $maximum) {
            $maximums[sprintf('spell-slot-%d', $level)] = $maximum;
        }

        return $maximums;
    }

    /**
     * @param array<int, array<string, mixed>> $states
     */
    private function findStateIndex(array $states, string $id): ?int
    {
        foreach ($states as $index => $state) {
            if (($state['id'] ?? null) === $id) {
                return $index;
            }
        }

        return null;
    }
}

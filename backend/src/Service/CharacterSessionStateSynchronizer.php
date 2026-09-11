<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterSessionState;
use App\Entity\ProgressionDefinition;
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
     * Synchronisation complète, avec initialisation des progressions manquantes.
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
        $progressions = $state['progressions'] ?? [];

        foreach ($character->getProgressions() as $characterProgression) {
            $definition = $characterProgression->getProgressionDefinition();
            $id = $definition->getSlug();

            if ($this->findStateIndex($progressions, $id) !== null) {
                continue;
            }

            $progressions[] = [
                'id' => $id,
                'currentValue' => $definition->getMinimumValue(),
            ];
        }

        $state['progressions'] = $progressions;

        return $this->synchronizeResources($character, $state);
    }

    /**
     * Conserve les historiques et ne touche jamais aux progressions.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function synchronizeResources(Character $character, array $state): array
    {
        $maximums = $this->resourceMaximums(
            $character,
            $this->extractProgressionValues($state),
        );
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

        $state['resources'] = $resources;

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, int>
     */
    public function extractProgressionValues(array $state): array
    {
        $progressions = $state['progressions'] ?? [];

        if (!is_array($progressions)) {
            return [];
        }

        $values = [];

        foreach ($progressions as $progression) {
            if (
                !is_array($progression)
                || !is_string($progression['id'] ?? null)
                || $progression['id'] === ''
                || !is_int($progression['currentValue'] ?? null)
            ) {
                continue;
            }

            $values[$progression['id']] = $progression['currentValue'];
        }

        return $values;
    }

    /**
     * Initialise la progression attribuée et ses nouvelles ressources actives.
     *
     * Les valeurs existantes ne sont jamais modifiées.
     */
    public function synchronizeProgressionAssignment(
        Character $character,
        ProgressionDefinition $definition,
    ): void {
        $sessionStates = $this->sessionStateRepository->findBy([
            'character' => $character,
        ]);

        foreach ($sessionStates as $sessionState) {
            $state = $sessionState->getState();
            $progressions = $state['progressions'] ?? [];

            $id = $definition->getSlug();
            if ($this->findStateIndex($progressions, $id) === null) {
                $progressions[] = [
                    'id' => $id,
                    'currentValue' =>
                        $definition->getMinimumValue(),
                ];
            }

            $state['progressions'] = $progressions;

            // Exclude resources already available independently of this assignment.
            // In particular, do not repair unrelated missing or stale resource state.
            $otherValues = $this->extractProgressionValues($state);
            unset($otherValues[$id]);
            $unrelatedMaximums = $this->resourceMaximums($character, $otherValues);
            $synchronized = $this->synchronizeResources($character, $state);
            foreach ($synchronized['resources'] as $resourceState) {
                $slug = $resourceState['id'];
                if (
                    !array_key_exists($slug, $unrelatedMaximums)
                    && $this->findStateIndex($state['resources'] ?? [], $slug) === null
                ) {
                    $state['resources'][] = $resourceState;
                }
            }
            $sessionState->setState($state);
        }
    }

    /**
     * Photographie les maximums dérivés du personnage avant une modification.
     *
     * @param array<string, int> $progressionValues Valeurs explicites de la session.
     *
     * @return array{
     *     hitPoints: int|null,
     *     hitDice: array<string, int>,
     *     resources: array<string, int>
     * }
     */
    public function snapshot(Character $character, array $progressionValues = []): array
    {
        $hitPoints = $this->hitPointCalculator->calculate($character);

        return [
            'hitPoints' => $hitPoints->isComplete()
                ? $hitPoints->maximumValue
                : null,
            'hitDice' => $this->hitDiceMaximums($character),
            'resources' => $this->resourceMaximums($character, $progressionValues),
        ];
    }

    /**
     * Capture each session's explicit progression context before changing the character.
     *
     * @return list<array{sessionState: CharacterSessionState, before: array{
     *     hitPoints: int|null, hitDice: array<string, int>, resources: array<string, int>
     * }}>
     */
    public function snapshotForLevelUp(Character $character): array
    {
        $snapshots = [];
        foreach ($this->sessionStateRepository->findBy(['character' => $character]) as $sessionState) {
            $snapshots[] = [
                'sessionState' => $sessionState,
                'before' => $this->snapshot($character, $this->extractProgressionValues($sessionState->getState())),
            ];
        }

        return $snapshots;
    }

    /**
     * Applique aux états de session uniquement ce qui vient d'être gagné
     * grâce au level-up.
     *
     * @param list<array{sessionState: CharacterSessionState, before: array{
     *     hitPoints: int|null,
     *     hitDice: array<string, int>,
     *     resources: array<string, int>
     * }}> $snapshots
     */
    public function synchronizeAfterLevelUp(
        Character $character,
        array $snapshots,
    ): void {
        foreach ($snapshots as $snapshot) {
            $sessionState = $snapshot['sessionState'];
            $before = $snapshot['before'];
            $state = $sessionState->getState();
            $after = $this->snapshot($character, $this->extractProgressionValues($state));

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
    private function resourceMaximums(
        Character $character,
        array $progressionValues = [],
    ): array {
        $maximums = [];

        foreach ($this->resourceResolver->resolve($character, $progressionValues) as $resource) {
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

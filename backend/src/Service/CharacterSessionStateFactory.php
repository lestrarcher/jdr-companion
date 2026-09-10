<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;

final readonly class CharacterSessionStateFactory
{
    public function __construct(
        private CharacterHitPointCalculator $hitPointCalculator,
        private CharacterResourceResolver $resourceResolver,
        private CharacterSpellSlotCalculator $spellSlotCalculator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(Character $character): array
    {
        $hitPoints = $this->hitPointCalculator->calculate($character);

        if (!$hitPoints->isComplete() || $hitPoints->maximumValue === null) {
            throw new \DomainException(sprintf(
                'Impossible d’ajouter %s à la session : les gains de PV des niveaux %s sont manquants.',
                $character->getName(),
                implode(', ', $hitPoints->missingLevelPositions),
            ));
        }

        $progressions = $this->createProgressions($character);
        $progressionValues = [];

        foreach ($progressions as $progression) {
            $progressionValues[$progression['id']] = $progression['currentValue'];
        }

        return [
            'hitPoints' => [
                'current' => $hitPoints->maximumValue,
                'temporary' => 0,
            ],
            'hitDice' => $this->createHitDice($character),
            'progressions' => $progressions,
            'resources' => $this->createResources($character, $progressionValues),
        ];
    }

    /**
     * @return list<array{id: string, current: int}>
     */
    private function createHitDice(Character $character): array
    {
        $hitDice = [];

        foreach ($character->getClassLevels() as $level) {
            $id = sprintf('d%d', $level->getCharacterClass()->getHitDie());

            if (!isset($hitDice[$id])) {
                $hitDice[$id] = [
                    'id' => $id,
                    'current' => 0,
                ];
            }

            ++$hitDice[$id]['current'];
        }

        return array_values($hitDice);
    }

    /**
     * @return list<array{id: string, currentValue: int}>
     */
    private function createProgressions(Character $character): array
    {
        $state = [];

        foreach ($character->getProgressions() as $characterProgression) {
            $definition =
                $characterProgression->getProgressionDefinition();

            $state[] = [
                'id' => $definition->getSlug(),
                'currentValue' => $definition->getMinimumValue(),
            ];
        }

        return $state;
    }

    /**
     * @return list<array{id: string, currentValue: int}>
     */
    private function createResources(
        Character $character,
        array $progressionValues,
    ): array {
        $resources = [];

        /*
         * Compatibilité temporaire avec les ressources personnalisées
         * encore présentes dans l’ancienne définition JSON.
         */
        $legacyResources = $character->getDefinition()['resources'] ?? [];

        if (is_array($legacyResources)) {
            foreach ($legacyResources as $resource) {
                if (!is_array($resource) || !isset($resource['id'])) {
                    continue;
                }

                $id = (string) $resource['id'];
                $resources[$id] = [
                    'id' => $id,
                    'currentValue' => (int) ($resource['maximumValue'] ?? 0),
                ];
            }
        }

        foreach ($this->resourceResolver->resolve($character, $progressionValues) as $resource) {
            $resources[$resource->getSlug()] = [
                'id' => $resource->getSlug(),
                'currentValue' => $resource->getMaximum(),
            ];
        }

        foreach ($this->spellSlotCalculator->calculate($character) as $level => $maximum) {
            $id = sprintf('spell-slot-%d', $level);

            $resources[$id] = [
                'id' => $id,
                'currentValue' => $maximum,
            ];
        }

        return array_values($resources);
    }
}

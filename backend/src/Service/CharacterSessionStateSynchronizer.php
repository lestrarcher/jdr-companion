<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;

final readonly class CharacterSessionStateSynchronizer
{
    public function __construct(
        private CharacterResourceResolver $resourceResolver,
    ) {
    }

    /**
     * Synchronise les données dynamiques avec l'état actuel du personnage
     * sans restaurer les ressources déjà consommées.
     *
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    public function synchronize(Character $character, array $state): array
    {
        $state['resources'] = $this->synchronizeResources(
            $character,
            $state['resources'] ?? [],
        );

        return $state;
    }

    /**
     * @param array<int, array<string, mixed>> $states
     *
     * @return list<array{id: string, currentValue: int}>
     */
    private function synchronizeResources(
        Character $character,
        array $states,
    ): array {
        $currentValues = [];

        foreach ($states as $resourceState) {
            if (
                !is_array($resourceState)
                || !isset($resourceState['id'])
            ) {
                continue;
            }

            $currentValues[(string) $resourceState['id']] =
                (int) ($resourceState['currentValue'] ?? 0);
        }

        $resources = [];

        foreach ($this->resourceResolver->resolve($character) as $resource) {
            $slug = $resource->getSlug();

            $resources[] = [
                'id' => $slug,
                'currentValue' => isset($currentValues[$slug])
                    ? min($currentValues[$slug], $resource->getMaximum())
                    : $resource->getMaximum(),
            ];
        }

        return $resources;
    }
}

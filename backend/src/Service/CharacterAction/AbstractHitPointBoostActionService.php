<?php

declare(strict_types=1);

namespace App\Service\CharacterAction;

use App\Entity\CharacterSessionState;

abstract readonly class AbstractHitPointBoostActionService extends \App\Service\CharacterHitPointBoostService
{
    protected function assertPrepared(CharacterSessionState $casterState, string $actionSlug, string $actionName): void
    {
        $prepared = $casterState->getState()['characterActions']['prepared'] ?? [];

        if (!is_array($prepared) || !in_array($actionSlug, $prepared, true)) {
            throw new \DomainException(sprintf('%s n’est pas préparé.', $actionName));
        }
    }

    protected function consumeSpellSlot(CharacterSessionState $casterState, int $spellSlotLevel): void
    {
        $state = $casterState->getState();
        $resourceId = sprintf('spell-slot-%d', $spellSlotLevel);
        $resources = $state['resources'] ?? [];

        foreach ($resources as $index => $resource) {
            if (!is_array($resource) || ($resource['id'] ?? null) !== $resourceId) {
                continue;
            }

            $currentValue = (int) ($resource['currentValue'] ?? 0);

            if ($currentValue <= 0) {
                throw new \DomainException(sprintf('Aucun emplacement de niveau %d n’est disponible.', $spellSlotLevel));
            }

            $resources[$index]['currentValue'] = $currentValue - 1;
            $state['resources'] = $resources;
            $casterState->setState($state);

            return;
        }

        throw new \DomainException(sprintf('Aucun emplacement de niveau %d n’est disponible.', $spellSlotLevel));
    }

}

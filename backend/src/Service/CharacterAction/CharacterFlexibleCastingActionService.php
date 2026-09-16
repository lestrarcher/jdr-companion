<?php

declare(strict_types=1);

namespace App\Service\CharacterAction;

use App\Entity\CharacterSessionState;
use App\Service\CharacterResourceResolver;
use App\Service\CharacterSessionStateSynchronizer;
use App\Service\CharacterSpellSlotStateService;

/** Eligibility and transactional persistence belong to the caller. */
final readonly class CharacterFlexibleCastingActionService
{
    private const SORCERY_POINTS = 'sorcery-points';
    private const CREATION_COSTS = [1 => 2, 2 => 3, 3 => 5, 4 => 6, 5 => 7];

    public function __construct(
        private CharacterResourceResolver $resourceResolver,
        private CharacterSpellSlotStateService $spellSlotStateService,
        private CharacterSessionStateSynchronizer $stateSynchronizer,
    ) {
    }

    public function createSpellSlot(CharacterSessionState $sessionState, int $level): void
    {
        $cost = self::CREATION_COSTS[$level] ?? null;
        if ($cost === null) throw new \DomainException('Le niveau de création doit être compris entre 1 et 5.');

        $state = $sessionState->getState();
        [$pointsIndex, $points] = $this->sorceryPoints($sessionState, $state);
        if ($points < $cost) throw new \DomainException('Points de sorcellerie insuffisants.');

        $id = sprintf('spell-slot-%d', $level);
        $index = $this->resourceIndex($state, $id);
        $slot = $index === null ? ['id' => $id, 'currentValue' => 0] : $state['resources'][$index];
        $current = $this->nonNegativeInteger($slot['currentValue'] ?? null);
        $bonus = $this->nonNegativeInteger($slot['flexibleCastingBonus'] ?? 0);
        $effectiveMaximum = $this->spellSlotStateService->effectiveMaximums($sessionState->getCharacter(), $state)[$level] ?? 0;
        if ($current > $effectiveMaximum) throw new \DomainException('État des emplacements invalide.');

        $slot['currentValue'] = $current + 1;
        $slot['flexibleCastingBonus'] = $bonus + 1;
        if ($index === null) {
            $state['resources'][] = $slot;
        } else {
            $state['resources'][$index] = $slot;
        }
        $state['resources'][$pointsIndex]['currentValue'] = $points - $cost;
        $sessionState->setState($state);
    }

    public function convertSpellSlotToSorceryPoints(CharacterSessionState $sessionState, int $level): void
    {
        if ($level < 1 || $level > 9) throw new \DomainException('Le niveau d’emplacement doit être compris entre 1 et 9.');

        $state = $sessionState->getState();
        [$pointsIndex, $points, $maximum] = $this->sorceryPoints($sessionState, $state);
        $index = $this->resourceIndex($state, sprintf('spell-slot-%d', $level));
        $effectiveMaximum = $this->spellSlotStateService->effectiveMaximums($sessionState->getCharacter(), $state)[$level] ?? 0;
        if ($index === null || $effectiveMaximum === 0) throw new \DomainException('Aucun emplacement standard disponible à ce niveau.');

        $current = $this->nonNegativeInteger($state['resources'][$index]['currentValue'] ?? null);
        if ($current === 0) throw new \DomainException('Aucun emplacement standard disponible à ce niveau.');
        if ($current > $effectiveMaximum) throw new \DomainException('État des emplacements invalide.');
        if ($level > $maximum - $points) throw new \DomainException('La conversion dépasserait le maximum de points de sorcellerie.');

        $state['resources'][$index]['currentValue'] = $current - 1;
        $state['resources'][$pointsIndex]['currentValue'] = $points + $level;
        $sessionState->setState($state);
    }

    /** @return array{int, int, int} Resource index, current points, resolved maximum. */
    private function sorceryPoints(CharacterSessionState $sessionState, array $state): array
    {
        $index = $this->resourceIndex($state, self::SORCERY_POINTS);
        $values = $this->stateSynchronizer->extractProgressionValues($state);
        $resource = $this->resourceResolver->resolve($sessionState->getCharacter(), $values)[self::SORCERY_POINTS] ?? null;
        if ($index === null || $resource === null) throw new \DomainException('La ressource points de sorcellerie est indisponible.');

        $current = $this->nonNegativeInteger($state['resources'][$index]['currentValue'] ?? null);
        if ($current > $resource->getMaximum()) throw new \DomainException('État des points de sorcellerie invalide.');

        return [$index, $current, $resource->getMaximum()];
    }

    private function resourceIndex(array $state, string $id): ?int
    {
        foreach ($state['resources'] ?? [] as $index => $resource) {
            if (($resource['id'] ?? null) === $id) return $index;
        }
        return null;
    }

    private function nonNegativeInteger(mixed $value): int
    {
        if (!is_int($value) || $value < 0) throw new \DomainException('Valeur de ressource invalide.');
        return $value;
    }
}

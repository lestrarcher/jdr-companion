<?php

declare(strict_types=1);

namespace App\Service\CharacterAction;

use App\Entity\Character;
use App\Entity\CharacterSessionState;
use App\Service\CharacterResourceResolver;
use App\Service\CharacterSessionStateSynchronizer;
use App\Service\CharacterSpellSlotStateService;

final readonly class CharacterArcaneRecoveryActionService
{
    private const RESOURCE = 'arcane-recovery';

    public function __construct(
        private CharacterResourceResolver $resourceResolver,
        private CharacterSpellSlotStateService $spellSlotStateService,
        private CharacterSessionStateSynchronizer $stateSynchronizer,
    ) {
    }

    /**
     * @param array<int, int> $slots Quantité d'emplacements à récupérer par niveau de sort.
     */
    public function recoverSpellSlots(CharacterSessionState $sessionState, array $slots): void
    {
        $state = $sessionState->getState();
        $character = $sessionState->getCharacter();
        $budget = (int) ceil($this->wizardLevel($character) / 2);

        if ($budget < 1) throw new \DomainException('Restauration arcanique est indisponible pour ce personnage.');
        if ($slots === []) throw new \DomainException('Au moins un emplacement doit être sélectionné.');

        $cost = 0;

        foreach ($slots as $level => $quantity) {
            if (!is_int($level) || $level < 1 || $level > 5) throw new \DomainException('Seuls les emplacements de niveau 1 à 5 peuvent être récupérés.');
            if (!is_int($quantity) || $quantity < 1) throw new \DomainException('La quantité d’emplacements à récupérer doit être positive.');
            $cost += $level * $quantity;
        }

        if ($cost > $budget) throw new \DomainException('La sélection dépasse la capacité de Restauration arcanique.');

        [$recoveryIndex, $recoveryCurrent] = $this->arcaneRecoveryResource($sessionState, $state);
        if ($recoveryCurrent < 1) throw new \DomainException('Restauration arcanique a déjà été utilisée.');

        $effectiveMaximums = $this->spellSlotStateService->effectiveMaximums($character, $state);
        $updates = [];

        foreach ($slots as $level => $quantity) {
            $id = sprintf('spell-slot-%d', $level);
            $index = $this->resourceIndex($state, $id);
            $maximum = $effectiveMaximums[$level] ?? 0;

            if ($index === null || $maximum < 1) throw new \DomainException(sprintf('Aucun emplacement de niveau %d ne peut être récupéré.', $level));

            $current = $this->nonNegativeInteger($state['resources'][$index]['currentValue'] ?? null);
            if ($current > $maximum) throw new \DomainException(sprintf('État des emplacements de niveau %d invalide.', $level));
            if ($current + $quantity > $maximum) throw new \DomainException(sprintf('La récupération dépasserait le maximum des emplacements de niveau %d.', $level));

            $updates[$index] = $current + $quantity;
        }

        foreach ($updates as $index => $current) {
            $state['resources'][$index]['currentValue'] = $current;
        }

        $state['resources'][$recoveryIndex]['currentValue'] = $recoveryCurrent - 1;
        $sessionState->setState($state);
    }

    /** @return array{int, int} Resource index and current value. */
    private function arcaneRecoveryResource(CharacterSessionState $sessionState, array $state): array
    {
        $index = $this->resourceIndex($state, self::RESOURCE);
        $values = $this->stateSynchronizer->extractProgressionValues($state);
        $resource = $this->resourceResolver->resolve($sessionState->getCharacter(), $values)[self::RESOURCE] ?? null;

        if ($index === null || $resource === null) throw new \DomainException('La ressource Restauration arcanique est indisponible.');

        $current = $this->nonNegativeInteger($state['resources'][$index]['currentValue'] ?? null);
        if ($current > $resource->getMaximum()) throw new \DomainException('État de Restauration arcanique invalide.');

        return [$index, $current];
    }

    private function wizardLevel(Character $character): int
    {
        foreach ($character->getClassLevels() as $classLevel) {
            $characterClass = $classLevel->getCharacterClass();

            if ($characterClass->getSlug() === 'wizard') return $character->getLevelInClass($characterClass);
        }

        return 0;
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

<?php

declare(strict_types=1);

namespace App\Service\CharacterAction;

use App\Entity\Character;
use App\Entity\CharacterActiveEffect;
use App\Entity\CharacterSessionState;
use App\Repository\CharacterActiveEffectRepository;
use App\Service\CharacterHitPointStateService;
use Doctrine\ORM\EntityManagerInterface;

abstract readonly class AbstractHitPointBoostActionService
{
    public function __construct(
        protected CharacterHitPointStateService $hitPointStateService,
        protected CharacterActiveEffectRepository $activeEffectRepository,
        protected EntityManagerInterface $entityManager,
    ) {
    }

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

    protected function applyHitPointBoost(Character $caster, CharacterSessionState $targetState, string $effectType, int $amount): void
    {
        $target = $targetState->getCharacter();
        $existingEffect = $this->activeEffectRepository->findEffectFor($target, $effectType);
        $existingAmount = $existingEffect?->getAmount() ?? 0;

        if ($amount <= $existingAmount) {
            return;
        }

        $delta = $amount - $existingAmount;
        $state = $this->hitPointStateService->adjustMaximum($target, $targetState->getState(), $delta);
        $current = (int) ($state['hitPoints']['current'] ?? 0);
        $state['hitPoints']['current'] = $current + $delta;
        $targetState->setState($state);

        if ($existingEffect === null) {
            $effect = new CharacterActiveEffect(
                targetCharacter: $target,
                sourceCharacter: $caster,
                type: $effectType,
                amount: $amount,
            );

            $this->entityManager->persist($effect);

            return;
        }

        $existingEffect->setSourceCharacter($caster);
        $existingEffect->setAmount($amount);
    }
}

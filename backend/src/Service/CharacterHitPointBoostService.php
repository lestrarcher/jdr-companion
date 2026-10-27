<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterActiveEffect;
use App\Entity\CharacterSessionState;
use App\Repository\CharacterActiveEffectRepository;
use Doctrine\ORM\EntityManagerInterface;

readonly class CharacterHitPointBoostService
{
    public function __construct(
        protected CharacterHitPointStateService $hitPointStateService,
        protected CharacterActiveEffectRepository $activeEffectRepository,
        protected EntityManagerInterface $entityManager,
    ) {
    }

    /** Preflight only; caller owns locks and the transaction. */
    public function validate(CharacterSessionState $targetState, string $effectType, int $amount): void
    {
        $effect = $this->activeEffectRepository->findEffectFor($targetState->getCharacter(), $effectType);
        if ($effect !== null) $this->entityManager->refresh($effect);
        $delta = max(0, $amount - ($effect?->getAmount() ?? 0));
        $this->hitPointStateService->adjustMaximum($targetState->getCharacter(), $targetState->getState(), $delta);
    }

    public function applyHitPointBoost(Character $caster, CharacterSessionState $targetState, string $effectType, int $amount): void
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

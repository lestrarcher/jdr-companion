<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CharacterActiveEffect;
use App\Entity\CharacterSessionState;

final readonly class CharacterActiveEffectService
{
    public function __construct(
        private CharacterHitPointStateService $hitPointStateService,
    ) {
    }

    public function terminate(
        CharacterSessionState $sessionState,
        CharacterActiveEffect $effect,
    ): void {
        $character = $sessionState->getCharacter();

        if (
            $effect->getTargetCharacter()->getId()
            !== $character->getId()
        ) {
            throw new \DomainException(
                'Cet effet n’appartient pas à ce personnage.',
            );
        }

        switch ($effect->getType()) {
            case CharacterActiveEffect::TYPE_AID:
                $state = $this->hitPointStateService->adjustMaximum(
                    $character,
                    $sessionState->getState(),
                    -$effect->getAmount(),
                );

                $sessionState->setState($state);

                break;

            default:
                throw new \DomainException(
                    'Ce type d’effet ne peut pas encore être terminé.',
                );
        }
    }
}

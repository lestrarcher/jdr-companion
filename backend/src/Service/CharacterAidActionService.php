<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterActiveEffect;
use App\Entity\CharacterSessionState;
use App\Repository\CharacterActiveEffectRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CharacterAidActionService
{
    public function __construct(
        private CharacterHitPointStateService $hitPointStateService,
        private CharacterActiveEffectRepository $activeEffectRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<CharacterSessionState> $targetStates
     */
    public function apply(
        CharacterSessionState $casterState,
        int $spellSlotLevel,
        array $targetStates,
    ): void {
        if ($spellSlotLevel < 2) {
            throw new \DomainException(
                'Aide nécessite un emplacement de niveau 2 ou supérieur.',
            );
        }

        if ($targetStates === [] || count($targetStates) > 3) {
            throw new \DomainException(
                'Aide doit cibler entre une et trois créatures.',
            );
        }

        $caster = $casterState->getCharacter();

        $this->assertPrepared($casterState);
        $this->consumeSpellSlot(
            $casterState,
            $spellSlotLevel,
        );

        $amount = 5 * ($spellSlotLevel - 1);

        foreach ($targetStates as $targetState) {
            $this->applyToTarget(
                $caster,
                $targetState,
                $amount,
            );
        }
    }

    private function assertPrepared(
        CharacterSessionState $casterState,
    ): void {
        $state = $casterState->getState();

        $prepared = $state['characterActions']['prepared']
            ?? [];

        if (
            !is_array($prepared)
            || !in_array('aid', $prepared, true)
        ) {
            throw new \DomainException(
                'Aide n’est pas préparé.',
            );
        }
    }

    private function consumeSpellSlot(
        CharacterSessionState $casterState,
        int $spellSlotLevel,
    ): void {
        $state = $casterState->getState();

        $resourceId = sprintf(
            'spell-slot-%d',
            $spellSlotLevel,
        );

        $resources = $state['resources'] ?? [];

        foreach ($resources as $index => $resource) {
            if (
                !is_array($resource)
                || ($resource['id'] ?? null) !== $resourceId
            ) {
                continue;
            }

            $currentValue = (int) (
                $resource['currentValue']
                ?? 0
            );

            if ($currentValue <= 0) {
                throw new \DomainException(
                    sprintf(
                        'Aucun emplacement de niveau %d n’est disponible.',
                        $spellSlotLevel,
                    ),
                );
            }

            $resources[$index]['currentValue']
                = $currentValue - 1;

            $state['resources'] = $resources;

            $casterState->setState($state);

            return;
        }

        throw new \DomainException(
            sprintf(
                'Aucun emplacement de niveau %d n’est disponible.',
                $spellSlotLevel,
            ),
        );
    }

    private function applyToTarget(
        Character $caster,
        CharacterSessionState $targetState,
        int $amount,
    ): void {
        $target = $targetState->getCharacter();

        $existingEffect =
            $this->activeEffectRepository
                ->findAidFor($target);

        $existingAmount =
            $existingEffect?->getAmount()
            ?? 0;

        if ($amount <= $existingAmount) {
            return;
        }

        $delta = $amount - $existingAmount;

        $state = $targetState->getState();

        $state =
            $this->hitPointStateService
                ->adjustMaximum(
                    $target,
                    $state,
                    $delta,
                );

        $current = (int) (
            $state['hitPoints']['current']
            ?? 0
        );

        $state['hitPoints']['current']
            = $current + $delta;

        $targetState->setState($state);

        if ($existingEffect === null) {
            $effect = new CharacterActiveEffect(
                targetCharacter: $target,
                sourceCharacter: $caster,
                type: CharacterActiveEffect::TYPE_AID,
                amount: $amount,
            );

            $this->entityManager->persist(
                $effect,
            );

            return;
        }

        $existingEffect->setSourceCharacter(
            $caster,
        );

        $existingEffect->setAmount(
            $amount,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Service\CharacterAction;

use App\Entity\CharacterActiveEffect;
use App\Entity\CharacterSessionState;

final readonly class CharacterHeroesFeastActionService extends AbstractHitPointBoostActionService
{
    /**
     * @param list<CharacterSessionState> $targetStates
     */
    public function apply(CharacterSessionState $casterState, int $hitPointBonus, array $targetStates): void
    {
        if ($hitPointBonus < 2 || $hitPointBonus > 20) {
            throw new \DomainException('Le résultat des 2d10 doit être compris entre 2 et 20.');
        }

        if ($targetStates === [] || count($targetStates) > 12) {
            throw new \DomainException('Festin des héros doit bénéficier à entre une et douze créatures.');
        }

        $this->assertPrepared($casterState, 'heroes-feast', 'Festin des héros');
        $this->consumeSpellSlot($casterState, 6);

        foreach ($targetStates as $targetState) {
            $this->applyHitPointBoost($casterState->getCharacter(), $targetState, CharacterActiveEffect::TYPE_HEROES_FEAST, $hitPointBonus);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Service\CharacterAction;

use App\Entity\CharacterActiveEffect;
use App\Entity\CharacterSessionState;

final readonly class CharacterAidActionService extends AbstractHitPointBoostActionService
{
    /**
     * @param list<CharacterSessionState> $targetStates
     */
    public function apply(CharacterSessionState $casterState, int $spellSlotLevel, array $targetStates): void
    {
        if ($spellSlotLevel < 2) {
            throw new \DomainException('Aide nécessite un emplacement de niveau 2 ou supérieur.');
        }

        if ($targetStates === [] || count($targetStates) > 3) {
            throw new \DomainException('Aide doit cibler entre une et trois créatures.');
        }

        $this->assertPrepared($casterState, 'aid', 'Aide');
        $this->consumeSpellSlot($casterState, $spellSlotLevel);

        $amount = 5 * ($spellSlotLevel - 1);

        foreach ($targetStates as $targetState) {
            $this->applyHitPointBoost($casterState->getCharacter(), $targetState, CharacterActiveEffect::TYPE_AID, $amount);
        }
    }
}

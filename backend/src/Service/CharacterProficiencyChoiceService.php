<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterProficiencyChoice;
use App\Entity\ProficiencyDefinition;
use App\Entity\ProficiencyGrantRule;

/**
 * Stores a complete selection for one rule, using entities from the same UnitOfWork.
 * The caller determines rule eligibility and owns the transaction/persist/flush.
 * Cross-source overlaps retain their provenance; they imply neither stacking nor
 * an automatic replacement. Automatic grants are never materialized here.
 */
final class CharacterProficiencyChoiceService
{
    /** @param list<ProficiencyDefinition> $selections */
    public function validate(ProficiencyGrantRule $rule, array $selections): void
    {
        $rule->validateRule();

        if (!array_is_list($selections) || count($selections) !== $rule->getChoiceCount()) {
            throw new \DomainException(sprintf(
                'La règle "%s" nécessite exactement %d choix de maîtrises.',
                $rule->getLabel(),
                $rule->getChoiceCount(),
            ));
        }

        $seen = [];
        foreach ($selections as $selection) {
            if (!$selection instanceof ProficiencyDefinition || !$rule->getOptions()->contains($selection)) {
                throw new \DomainException('La maîtrise sélectionnée ne fait pas partie des options de la règle.');
            }

            if (in_array($selection, $seen, true)) {
                throw new \DomainException('Une maîtrise ne peut être choisie deux fois pour la même règle.');
            }
            $seen[] = $selection;
        }
    }

    /**
     * Validate before changing anything, preserving other rules and unchanged rows.
     * @param list<ProficiencyDefinition> $selections
     */
    public function replaceChoices(Character $character, ProficiencyGrantRule $rule, array $selections): void
    {
        $this->validate($rule, $selections);

        $retained = [];
        foreach ($character->getProficiencyChoices()->toArray() as $choice) {
            if ($choice->getGrantRule() !== $rule) {
                continue;
            }

            $definition = $choice->getProficiencyDefinition();
            if (in_array($definition, $selections, true) && !in_array($definition, $retained, true)) {
                $retained[] = $definition;
            } else {
                $character->removeProficiencyChoice($choice);
            }
        }

        foreach ($selections as $selection) {
            if (!in_array($selection, $retained, true)) {
                $character->addProficiencyChoice(new CharacterProficiencyChoice($character, $rule, $selection));
            }
        }
    }
}

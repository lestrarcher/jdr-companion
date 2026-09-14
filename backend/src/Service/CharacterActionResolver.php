<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterActionDefinition;
use App\Repository\CharacterActionClassRuleRepository;

final readonly class CharacterActionResolver
{
    public function __construct(
        private CharacterActionClassRuleRepository $ruleRepository,
    ) {
    }

    /**
     * @return array<string, CharacterActionDefinition>
     */
    public function resolve(Character $character): array
    {
        $actions = [];

        foreach ($this->ruleRepository->findActiveOrdered() as $rule) {
            if (
                $character->getLevelInClass($rule->getCharacterClass())
                < $rule->getUnlockLevel()
            ) {
                continue;
            }

            $definition = $rule->getActionDefinition();

            $actions[$definition->getSlug()] = $definition;
        }

        return $actions;
    }
}

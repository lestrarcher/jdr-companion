<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ResolvedCharacterResource;
use App\Entity\Character;
use App\Entity\CharacterRace;
use App\Entity\TrackableResourceDefinition;
use App\Entity\TrackableResourceRule;
use App\Enum\ResourceMaximumType;
use App\Repository\TrackableResourceRuleRepository;

final readonly class CharacterResourceResolver
{
    public function __construct(
        private TrackableResourceRuleRepository $ruleRepository,
        private CharacterAbilityCalculator $abilityCalculator,
    ) {
    }

    /**
     * @return array<string, ResolvedCharacterResource>
     */
    public function resolve(Character $character): array
    {
        $resolvedRules = [];

        foreach ($this->ruleRepository->findOrderedRules() as $rule) {
            if ($this->isRuleApplicable($character, $rule)) {
                $resolvedRules[$rule->getResourceDefinition()->getSlug()] = $rule;
            }
        }

        $resources = [];

        foreach ($resolvedRules as $slug => $rule) {
            $definition = $rule->getResourceDefinition();

            $resources[$slug] = new ResolvedCharacterResource(
                $definition,
                $this->resolveMaximum($character, $definition, $rule),
            );
        }

        return $resources;
    }

    private function isRuleApplicable(
        Character $character,
        TrackableResourceRule $rule,
    ): bool {
        $characterClass = $rule->getCharacterClass();

        if ($characterClass !== null) {
            return $character->getLevelInClass($characterClass) >= $rule->getUnlockLevel();
        }

        $subclass = $rule->getCharacterSubclass();

        if ($subclass !== null) {
            $characterClass = $subclass->getCharacterClass();

            return
                $character->getLevelInClass($characterClass) >= $rule->getUnlockLevel()
                && $character->getSubclassFor($characterClass) === $subclass;
        }

        $race = $rule->getCharacterRace();

        if ($race !== null) {
            return
                $character->getTotalLevel() >= $rule->getUnlockLevel()
                && $this->hasRaceOrParent($character->getRace(), $race);
        }

        $feat = $rule->getFeat();

        return
            $feat !== null
            && $character->getTotalLevel() >= $rule->getUnlockLevel()
            && $character->hasFeat($feat);
    }

    private function resolveMaximum(
        Character $character,
        TrackableResourceDefinition $definition,
        TrackableResourceRule $rule,
    ): int {
        if ($rule->getMaximumOverride() !== null) {
            return $rule->getMaximumOverride();
        }

        $maximum = match ($definition->getMaximumType()) {
            ResourceMaximumType::Fixed =>
                $definition->getBaseMaximum(),

            ResourceMaximumType::ProficiencyBonus =>
                $definition->getBaseMaximum()
                + ($character->getProficiencyBonus() * $definition->getMultiplier()),

            ResourceMaximumType::AbilityModifier =>
                $this->resolveAbilityMaximum($character, $definition),
        };

        return max($definition->getMinimumMaximum(), $maximum);
    }

    private function resolveAbilityMaximum(
        Character $character,
        TrackableResourceDefinition $definition,
    ): int {
        $ability = $definition->getScalingAbility();

        if ($ability === null) {
            throw new \LogicException(sprintf(
                'La ressource "%s" ne possède aucune caractéristique de calcul.',
                $definition->getName(),
            ));
        }

        $modifier = $this->abilityCalculator->calculate($character, $ability)->modifier();

        return $definition->getBaseMaximum()
            + ($modifier * $definition->getMultiplier());
    }

    private function hasRaceOrParent(
        ?CharacterRace $characterRace,
        CharacterRace $expectedRace,
    ): bool {
        while ($characterRace !== null) {
            if ($characterRace === $expectedRace) {
                return true;
            }

            $characterRace = $characterRace->getParentRace();
        }

        return false;
    }
}

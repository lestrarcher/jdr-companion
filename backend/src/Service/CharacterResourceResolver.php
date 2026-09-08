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
        private CharacterFeatureResolver $featureResolver,
    ) {
    }

    /**
     * @return array<string, ResolvedCharacterResource>
     */
    public function resolve(Character $character): array
    {
        /** @var array<string, TrackableResourceDefinition> $definitions */
        $definitions = [];

        /** @var array<string, TrackableResourceRule> $progressionRules */
        $progressionRules = [];

        /*
         * Une capacité débloquée peut donner accès directement
         * à une ressource, sans règle de progression supplémentaire.
         *
         * Exemple : Conscience magique, dont le maximum est égal
         * au bonus de maîtrise.
         */
        foreach ($this->featureResolver->resolve($character) as $featureRule) {
            $definition = $featureRule
                ->getFeatureDefinition()
                ->getResourceDefinition();

            if ($definition === null) {
                continue;
            }

            $definitions[$definition->getSlug()] = $definition;
        }

        /*
         * Les règles de ressource servent :
         * - aux ressources configurées avant les capacités ;
         * - aux paliers de maximum, comme Rage ou Présage supérieur.
         */
        foreach ($this->ruleRepository->findOrderedRules() as $rule) {
            if (!$this->isRuleApplicable($character, $rule)) {
                continue;
            }

            $definition = $rule->getResourceDefinition();
            $slug = $definition->getSlug();
            $currentRule = $progressionRules[$slug] ?? null;

            $definitions[$slug] = $definition;

            if (
                !$currentRule instanceof TrackableResourceRule
                || $rule->getUnlockLevel() > $currentRule->getUnlockLevel()
            ) {
                $progressionRules[$slug] = $rule;
            }
        }

        $resources = [];

        foreach ($definitions as $slug => $definition) {
            $resources[$slug] = new ResolvedCharacterResource(
                definition: $definition,
                maximum: $this->resolveMaximum(
                    $character,
                    $definition,
                    $progressionRules[$slug] ?? null,
                ),
            );
        }

        uasort(
            $resources,
            static fn (
                ResolvedCharacterResource $first,
                ResolvedCharacterResource $second,
            ): int => $first
                ->getDefinition()
                ->getName()
                <=> $second
                    ->getDefinition()
                    ->getName(),
        );

        return $resources;
    }

    private function isRuleApplicable(
        Character $character,
        TrackableResourceRule $rule,
    ): bool {
        $characterClass = $rule->getCharacterClass();

        if ($characterClass !== null) {
            return $character->getLevelInClass($characterClass)
                >= $rule->getUnlockLevel();
        }

        $subclass = $rule->getCharacterSubclass();

        if ($subclass !== null) {
            $characterClass = $subclass->getCharacterClass();

            return $character->getLevelInClass($characterClass)
                >= $rule->getUnlockLevel()
                && $character->getSubclassFor($characterClass) === $subclass;
        }

        $race = $rule->getCharacterRace();

        if ($race !== null) {
            return $character->getTotalLevel() >= $rule->getUnlockLevel()
                && $this->hasRaceOrParent($character->getRace(), $race);
        }

        $feat = $rule->getFeat();

        return $feat !== null
            && $character->getTotalLevel() >= $rule->getUnlockLevel()
            && $character->hasFeat($feat);
    }

    private function resolveMaximum(
        Character $character,
        TrackableResourceDefinition $definition,
        ?TrackableResourceRule $progressionRule,
    ): int {
        if ($progressionRule?->getMaximumOverride() !== null) {
            return $progressionRule->getMaximumOverride();
        }

        $maximum = match ($definition->getMaximumType()) {
            ResourceMaximumType::Fixed => $definition->getBaseMaximum(),

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

        $modifier = $this->abilityCalculator
            ->calculate($character, $ability)
            ->modifier();

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

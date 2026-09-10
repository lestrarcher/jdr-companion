<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterSessionState;
use App\Entity\RestRequest;
use App\Entity\TrackableResourceDefinition;
use App\Enum\ResourceMaximumType;
use App\Repository\TrackableResourceDefinitionRepository;

final readonly class CharacterRestService
{
    public function __construct(
        private CharacterHitPointCalculator $hitPointCalculator,
        private CharacterResourceResolver $resourceResolver,
        private CharacterSessionStateSynchronizer $stateSynchronizer,
        private CharacterSpellSlotCalculator $spellSlotCalculator,
        private TrackableResourceDefinitionRepository $resourceDefinitionRepository,
        private CharacterAbilityCalculator $abilityCalculator,
    ) {
    }

    public function apply(
        CharacterSessionState $sessionState,
        string $restType,
    ): void {
        $character = $sessionState->getCharacter();

        $state = $this->stateSynchronizer->synchronizeResources(
            $character,
            $sessionState->getState(),
        );

        if ($restType === RestRequest::TYPE_SHORT_REST) {
            $state = $this->applyShortRest($character, $state);
        } elseif ($restType === RestRequest::TYPE_LONG_REST) {
            $state = $this->applyLongRest($character, $state);
        } else {
            throw new \InvalidArgumentException(sprintf(
                'Type de repos invalide : "%s".',
                $restType,
            ));
        }

        $sessionState->setState($state);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function applyShortRest(
        Character $character,
        array $state,
    ): array {
        $state['resources'] = $this->resetResources(
            $character,
            $state['resources'] ?? [],
            [RestRequest::TYPE_SHORT_REST],
            $this->stateSynchronizer->extractProgressionValues($state),
        );

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function applyLongRest(
        Character $character,
        array $state,
    ): array {
        $hitPoints = $this->hitPointCalculator->calculate($character);

        if ($hitPoints->isComplete() && $hitPoints->maximumValue !== null) {
            $state['hitPoints']['current'] = $hitPoints->maximumValue;
        }

        $state['hitPoints']['temporary'] = 0;

        $state['hitDice'] = $this->restoreHitDice(
            $character,
            $state['hitDice'] ?? [],
        );

        $state['resources'] = $this->resetResources(
            $character,
            $state['resources'] ?? [],
            [
                RestRequest::TYPE_SHORT_REST,
                RestRequest::TYPE_LONG_REST,
            ],
            $this->stateSynchronizer->extractProgressionValues($state),
        );

        $state['resources'] = $this->restoreSpellSlots(
            $character,
            $state['resources'],
        );

        return $state;
    }

    /**
     * @param array<int, array<string, mixed>> $states
     * @param list<string>                     $resetPeriods
     * @param array<string, int>               $progressionValues
     *
     * @return array<int, array<string, mixed>>
     */
    private function resetResources(
        Character $character,
        array $states,
        array $resetPeriods,
        array $progressionValues,
    ): array {
        $resolvedResources = $this->resourceResolver->resolve($character, $progressionValues);
        $historicalSlugs = [];

        foreach ($states as $resourceState) {
            $id = $resourceState['id'] ?? null;

            if (is_string($id) && !isset($resolvedResources[$id])) {
                $historicalSlugs[$id] = $id;
            }
        }

        $historicalDefinitions = [];

        if ($historicalSlugs !== []) {
            foreach ($this->resourceDefinitionRepository->findBy([
                'slug' => array_values($historicalSlugs),
            ]) as $definition) {
                $historicalDefinitions[$definition->getSlug()] = $definition;
            }
        }

        return array_map(
            function (array $resourceState) use (
                $character,
                $resolvedResources,
                $historicalDefinitions,
                $resetPeriods,
            ): array {
                $id = $resourceState['id'] ?? null;

                if (!is_string($id)) {
                    return $resourceState;
                }

                $resource = $resolvedResources[$id] ?? null;
                $definition = $resource?->getDefinition()
                    ?? $historicalDefinitions[$id] ?? null;

                if ($definition === null) {
                    return $resourceState;
                }

                if (!in_array(
                    $definition->getRechargeType()->value,
                    $resetPeriods,
                    true,
                )) {
                    return $resourceState;
                }

                $resourceState['currentValue'] = $resource?->getMaximum()
                    ?? $this->historicalResourceMaximum($character, $definition);

                return $resourceState;
            },
            $states,
        );
    }

    private function historicalResourceMaximum(
        Character $character,
        TrackableResourceDefinition $definition,
    ): int {
        $scalingValue = match ($definition->getMaximumType()) {
            ResourceMaximumType::Fixed => 0,
            ResourceMaximumType::ProficiencyBonus => $character->getProficiencyBonus(),
            ResourceMaximumType::AbilityModifier => $this->abilityCalculator->calculate(
                $character,
                $definition->getScalingAbility()
                    ?? throw new \LogicException(sprintf(
                        'La ressource "%s" ne possède aucune caractéristique de calcul.',
                        $definition->getName(),
                    )),
            )->modifier(),
        };

        return max(
            $definition->getMinimumMaximum(),
            $definition->getBaseMaximum() + $scalingValue * $definition->getMultiplier(),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $states
     *
     * @return array<int, array<string, mixed>>
     */
    private function restoreHitDice(
        Character $character,
        array $states,
    ): array {
        $maximums = [];

        foreach ($character->getClassLevels() as $level) {
            $id = sprintf(
                'd%d',
                $level->getCharacterClass()->getHitDie(),
            );

            $maximums[$id] = ($maximums[$id] ?? 0) + 1;
        }

        return array_map(
            static function (array $hitDiceState) use ($maximums): array {
                $id = $hitDiceState['id'] ?? null;

                if (!is_string($id)) {
                    return $hitDiceState;
                }

                $maximum = $maximums[$id] ?? null;
                $current = $hitDiceState['current'] ?? null;

                if (!is_int($maximum) || !is_int($current)) {
                    return $hitDiceState;
                }

                $recovered = max(
                    1,
                    (int) floor($maximum / 2),
                );

                $hitDiceState['current'] = min(
                    $maximum,
                    $current + $recovered,
                );

                return $hitDiceState;
            },
            $states,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $states
     *
     * @return array<int, array<string, mixed>>
     */
    private function restoreSpellSlots(
        Character $character,
        array $states,
    ): array {
        $maximums = $this->spellSlotCalculator->calculate($character);

        foreach ($states as &$state) {
            $id = (string) ($state['id'] ?? '');

            if (!str_starts_with($id, 'spell-slot-')) {
                continue;
            }

            $level = (int) str_replace('spell-slot-', '', $id);
            $maximum = $maximums[$level] ?? null;

            if ($maximum === null) {
                continue;
            }

            $state['currentValue'] = $maximum;
        }

        unset($state);

        return $states;
    }
}

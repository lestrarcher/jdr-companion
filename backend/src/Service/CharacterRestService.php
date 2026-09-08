<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterSessionState;
use App\Entity\RestRequest;

final readonly class CharacterRestService
{
    public function __construct(
        private CharacterHitPointCalculator $hitPointCalculator,
        private CharacterResourceResolver $resourceResolver,
        private CharacterSessionStateSynchronizer $stateSynchronizer,
        private CharacterSpellSlotCalculator $spellSlotCalculator,
    ) {
    }

    public function apply(
        CharacterSessionState $sessionState,
        string $restType,
    ): void {
        $character = $sessionState->getCharacter();

        $state = $this->stateSynchronizer->synchronize(
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
     *
     * @return array<int, array<string, mixed>>
     */
    private function resetResources(
        Character $character,
        array $states,
        array $resetPeriods,
    ): array {
        $resolvedResources = $this->resourceResolver->resolve($character);

        return array_map(
            static function (array $resourceState) use (
                $resolvedResources,
                $resetPeriods,
            ): array {
                $id = $resourceState['id'] ?? null;

                if (!is_string($id)) {
                    return $resourceState;
                }

                $resource = $resolvedResources[$id] ?? null;

                if ($resource === null) {
                    return $resourceState;
                }

                if (!in_array(
                    $resource->getRechargeType()->value,
                    $resetPeriods,
                    true,
                )) {
                    return $resourceState;
                }

                $resourceState['currentValue'] = $resource->getMaximum();

                return $resourceState;
            },
            $states,
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

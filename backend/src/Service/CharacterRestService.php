<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CharacterSessionState;
use App\Entity\RestRequest;

final class CharacterRestService
{
    public function apply(
        CharacterSessionState $sessionState,
        string $restType,
    ): void {
        $state = $sessionState->getState();

        $definition = $sessionState
            ->getCharacter()
            ->getDefinition();

        if ($restType === RestRequest::TYPE_SHORT_REST) {
            $state = $this->applyShortRest(
                $state,
                $definition,
            );
        } elseif ($restType === RestRequest::TYPE_LONG_REST) {
            $state = $this->applyLongRest(
                $state,
                $definition,
            );
        } else {
            throw new \InvalidArgumentException(
                sprintf(
                    'Type de repos invalide : "%s".',
                    $restType,
                ),
            );
        }

        $sessionState->setState($state);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function applyShortRest(
        array $state,
        array $definition,
    ): array {
        $state['resources'] =
            $this->resetResources(
                $state['resources'] ?? [],
                $definition['resources'] ?? [],
                [
                    RestRequest::TYPE_SHORT_REST,
                ],
            );

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function applyLongRest(
        array $state,
        array $definition,
    ): array {
        $maximumHitPoints =
            $definition['hitPoints']['maximum']
            ?? null;

        if (is_int($maximumHitPoints)) {
            $state['hitPoints']['current'] =
                $maximumHitPoints;
        }

        $state['hitPoints']['temporary'] = 0;

        $state['hitDice'] =
            $this->restoreHitDice(
                $state['hitDice'] ?? [],
                $definition['hitDice'] ?? [],
            );

        $state['resources'] =
            $this->resetResources(
                $state['resources'] ?? [],
                $definition['resources'] ?? [],
                [
                    RestRequest::TYPE_SHORT_REST,
                    RestRequest::TYPE_LONG_REST,
                ],
            );

        return $state;
    }

    /**
     * @param array<int, array<string, mixed>> $states
     * @param array<int, array<string, mixed>> $definitions
     * @param list<string>                     $resetPeriods
     *
     * @return array<int, array<string, mixed>>
     */
    private function resetResources(
        array $states,
        array $definitions,
        array $resetPeriods,
    ): array {
        return array_map(
            function (array $resourceState) use (
                $definitions,
                $resetPeriods,
            ): array {
                $resourceDefinition =
                    $this->findById(
                        $definitions,
                        $resourceState['id'] ?? null,
                    );

                if ($resourceDefinition === null) {
                    return $resourceState;
                }

                $resetPeriod =
                    $resourceDefinition['resetPeriod']
                    ?? null;

                if (
                    !is_string($resetPeriod) ||
                    !in_array(
                        $resetPeriod,
                        $resetPeriods,
                        true,
                    )
                ) {
                    return $resourceState;
                }

                /*
                 * Les ressources à valeurs stockées,
                 * comme Présage, doivent recevoir de
                 * nouvelles valeurs après le repos.
                 */
                if (
                    isset(
                        $resourceDefinition[
                            'storedValuesConfig'
                        ],
                    )
                ) {
                    $resourceState['currentValue'] = 0;

                    unset(
                        $resourceState['storedValues'],
                    );

                    return $resourceState;
                }

                $maximumValue =
                    $resourceDefinition['maximumValue']
                    ?? null;

                if (is_int($maximumValue)) {
                    $resourceState['currentValue'] =
                        $maximumValue;
                }

                return $resourceState;
            },
            $states,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $states
     * @param array<int, array<string, mixed>> $definitions
     *
     * @return array<int, array<string, mixed>>
     */
    private function restoreHitDice(
        array $states,
        array $definitions,
    ): array {
        return array_map(
            function (array $hitDiceState) use (
                $definitions,
            ): array {
                $hitDiceDefinition =
                    $this->findById(
                        $definitions,
                        $hitDiceState['id'] ?? null,
                    );

                if ($hitDiceDefinition === null) {
                    return $hitDiceState;
                }

                $maximum =
                    $hitDiceDefinition['maximum']
                    ?? null;

                $current =
                    $hitDiceState['current']
                    ?? null;

                if (
                    !is_int($maximum) ||
                    !is_int($current)
                ) {
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
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<string, mixed>|null
     */
    private function findById(
        array $items,
        mixed $id,
    ): ?array {
        if (!is_string($id)) {
            return null;
        }

        foreach ($items as $item) {
            if (
                is_array($item) &&
                ($item['id'] ?? null) === $id
            ) {
                return $item;
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CharacterRace;

final readonly class CharacterRaceMetadataResolver
{
    /**
     * @return array{
     *     sizeOptions: list<string>,
     *     walkingSpeed: float|null,
     *     movementSpeeds: array<string, float>,
     *     languages: list<string>,
     *     languageChoiceCount: int,
     *     senses: array<string, float>,
     *     damageResistances: list<string>,
     *     damageImmunities: list<string>,
     *     conditionImmunities: list<string>
     * }
     */
    public function resolve(CharacterRace $race): array
    {
        $metadata = $race->getParentRace() !== null
            ? $this->resolve($race->getParentRace())
            : [
                'sizeOptions' => [],
                'walkingSpeed' => null,
                'movementSpeeds' => [],
                'languages' => [],
                'languageChoiceCount' => 0,
                'senses' => [],
                'damageResistances' => [],
                'damageImmunities' => [],
                'conditionImmunities' => [],
            ];

        if ($race->getSizeOptions() !== null) {
            $metadata['sizeOptions'] = $race->getSizeOptions();
        }

        if ($race->getWalkingSpeed() !== null) {
            $metadata['walkingSpeed'] = $race->getWalkingSpeed();
        }

        foreach ($race->getMovementSpeeds() ?? [] as $type => $speed) {
            if ($speed === null) {
                unset($metadata['movementSpeeds'][$type]);
            } else {
                $metadata['movementSpeeds'][$type] = $speed;
            }
        }

        $metadata['languages'] = $this->mergeLists($metadata['languages'], $race->getLanguages());

        if ($race->getLanguageChoiceCount() !== null) {
            $metadata['languageChoiceCount'] = $race->getLanguageChoiceCount();
        }

        foreach ($race->getSenses() ?? [] as $type => $distance) {
            if ($distance === null) {
                unset($metadata['senses'][$type]);
            } else {
                $metadata['senses'][$type] = $distance;
            }
        }

        $metadata['damageResistances'] = $this->mergeLists($metadata['damageResistances'], $race->getDamageResistances());
        $metadata['damageImmunities'] = $this->mergeLists($metadata['damageImmunities'], $race->getDamageImmunities());
        $metadata['conditionImmunities'] = $this->mergeLists($metadata['conditionImmunities'], $race->getConditionImmunities());

        return $metadata;
    }

    /** @param list<string> $inherited
     *  @param list<string>|null $local
     *  @return list<string>
     */
    private function mergeLists(array $inherited, ?array $local): array
    {
        return $local === null ? $inherited : array_values(array_unique([...$inherited, ...$local]));
    }
}

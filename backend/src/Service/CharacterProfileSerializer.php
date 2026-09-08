<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterClassLevel;
use App\Entity\CharacterFeatureRule;
use App\Entity\CharacterFeat;
use App\Enum\Ability;

final readonly class CharacterProfileSerializer
{
    public function __construct(
        private CharacterAbilityCalculator $abilityCalculator,
        private CharacterFeatureResolver $featureResolver,
        private CharacterResourceResolver $resourceResolver,
        private CharacterHitPointCalculator $hitPointCalculator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Character $character): array
    {
        return [
            'id' => $character->getId(),
            'campaignId' => $character->getCampaign()->getId(),
            'slug' => $character->getSlug(),
            'name' => $character->getName(),
            'playerName' => $character->getPlayerName(),
            'type' => $character->getType(),
            'race' => $character->getRace() !== null
                ? [
                    'id' => $character->getRace()->getId(),
                    'slug' => $character->getRace()->getSlug(),
                    'name' => $character->getRace()->getName(),
                ]
                : null,
            'totalLevel' => $character->getTotalLevel(),
            'proficiencyBonus' => $character->getProficiencyBonus(),
            'hitPoints' => $this->hitPointCalculator->calculate($character)->toArray(),
            'classLevels' => array_map(
                $this->serializeClassLevel(...),
                $character->getClassLevels()->toArray(),
            ),
            'classSummary' => $this->serializeClassSummary($character),
            'abilities' => array_map(
                fn (Ability $ability): array =>
                    $this->abilityCalculator->calculate($character, $ability)->toArray(),
                Ability::cases(),
            ),
            'feats' => array_map(
                $this->serializeFeat(...),
                $character->getFeats()->toArray(),
            ),
            'features' => array_values(array_map(
                $this->serializeFeature(...),
                $this->featureResolver->resolve($character),
            )),
            'resources' => array_values(array_map(
                static fn ($resource): array => [
                    'slug' => $resource->getSlug(),
                    'name' => $resource->getName(),
                    'maximum' => $resource->getMaximum(),
                    'rechargeType' => $resource->getRechargeType()->value,
                ],
                $this->resourceResolver->resolve($character),
            )),
            'definition' => $character->getDefinition(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeClassLevel(CharacterClassLevel $level): array
    {
        $method = $level->getHitPointGainMethod();

        return [
            'position' => $level->getPosition(),
            'classId' => $level->getCharacterClass()->getId(),
            'classSlug' => $level->getCharacterClass()->getSlug(),
            'className' => $level->getCharacterClass()->getName(),
            'hitDie' => $level->getCharacterClass()->getHitDie(),
            'subclassId' => $level->getSubclass()?->getId(),
            'subclassSlug' => $level->getSubclass()?->getSlug(),
            'subclassName' => $level->getSubclass()?->getName(),
            'hitPointGain' => $level->getHitPointGain(),
            'hitPointGainMethod' => $method?->value,
            'hitPointGainMethodLabel' => $method?->label(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeClassSummary(Character $character): array
    {
        $classes = [];

        foreach ($character->getClassLevels() as $level) {
            $characterClass = $level->getCharacterClass();
            $classId = $characterClass->getId();

            if ($classId === null) {
                continue;
            }

            if (!isset($classes[$classId])) {
                $subclass = $character->getSubclassFor($characterClass);

                $classes[$classId] = [
                    'classId' => $classId,
                    'classSlug' => $characterClass->getSlug(),
                    'className' => $characterClass->getName(),
                    'level' => 0,
                    'hitDie' => $characterClass->getHitDie(),
                    'subclassId' => $subclass?->getId(),
                    'subclassSlug' => $subclass?->getSlug(),
                    'subclassName' => $subclass?->getName(),
                ];
            }

            ++$classes[$classId]['level'];
        }

        return array_values($classes);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFeat(CharacterFeat $characterFeat): array
    {
        $feat = $characterFeat->getFeat();

        return [
            'id' => $characterFeat->getId(),
            'featId' => $feat->getId(),
            'slug' => $feat->getSlug(),
            'name' => $feat->getName(),
            'description' => $feat->getDescription(),
            'chosenAbility' => $characterFeat->getChosenAbility()?->value,
            'abilityIncrease' => $characterFeat->getAbilityIncrease(),
            'acquiredAtLevel' => $characterFeat->getAcquiredAtLevel(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFeature(CharacterFeatureRule $rule): array
    {
        $feature = $rule->getFeatureDefinition();
        $resource = $feature->getResourceDefinition();

        return [
            'id' => $feature->getId(),
            'slug' => $feature->getSlug(),
            'name' => $feature->getName(),
            'description' => $feature->getDescription(),
            'activationType' => $feature->getActivationType()->value,
            'visible' => $feature->isVisible(),
            'custom' => $feature->isCustom(),
            'sourceType' => $rule->sourceType(),
            'sourceId' => $rule->sourceId(),
            'sourceName' => $rule->sourceName(),
            'unlockLevel' => $rule->getUnlockLevel(),
            'displayOrder' => $rule->getDisplayOrder(),
            'resource' => $resource !== null
                ? [
                    'id' => $resource->getId(),
                    'slug' => $resource->getSlug(),
                    'name' => $resource->getName(),
                ]
                : null,
        ];
    }
}

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
        private CharacterSpellSlotCalculator $spellSlotCalculator,
    ) {
    }

    /**
     * @param array<string, int> $progressionValues Valeurs courantes par slug.
     *
     * @return array<string, mixed>
     */
    public function serialize(
        Character $character,
        array $progressionValues = [],
    ): array {
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
            'progressions' => array_map(
                static function ($characterProgression): array {
                    $definition =
                        $characterProgression->getProgressionDefinition();

                    return [
                        'id' => $characterProgression->getId(),
                        'definitionId' => $definition->getId(),
                        'slug' => $definition->getSlug(),
                        'name' => $definition->getName(),
                        'description' =>$definition->getDescription(),
                        'minimumValue' =>$definition->getMinimumValue(),
                        'maximumValue' =>$definition->getMaximumValue(),
                        'accentColor' =>$definition->getAccentColor(),
                        'gainLabel' =>$definition->getGainLabel(),
                        'spendLabel' =>$definition->getSpendLabel(),
                        'bulkAdjustmentEnabled' => $definition->isBulkAdjustmentEnabled(),
                        'stages' => array_map(
                            static fn ($stage): array => [
                                'id' => $stage->getId(),
                                'label' => $stage->getLabel(),
                                'minimumValue' => $stage->getMinimumValue(),
                                'maximumValue' => $stage->getMaximumValue(),
                                'iconUrl' => $stage->getIconUrl(),
                                'displayOrder' => $stage->getDisplayOrder(),
                            ],
                            $definition->getStages()->toArray(),
                        ),
                    ];
                },
                $character->getProgressions()->toArray(),
            ),
            'features' => array_values(array_map(
                $this->serializeFeature(...),
                $this->featureResolver->resolve($character, $progressionValues),
            )),
            'resources' => $this->serializeResources($character, $progressionValues),
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

    /**
     * @param array<string, int> $progressionValues
     *
     * @return list<array{
     *     slug: string,
     *     name: string,
     *     maximum: int,
     *     rechargeType: string
     * }>
     */
    private function serializeResources(
        Character $character,
        array $progressionValues,
    ): array {
        $resources = [];

        foreach ($this->resourceResolver->resolve($character, $progressionValues) as $resource) {
            $resources[$resource->getSlug()] = [
                'slug' => $resource->getSlug(),
                'name' => $resource->getName(),
                'maximum' => $resource->getMaximum(),
                'rechargeType' => $resource->getRechargeType()->value,
            ];
        }

        foreach ($this->spellSlotCalculator->calculate($character) as $level => $maximum) {
            $slug = sprintf('spell-slot-%d', $level);

            $resources[$slug] = [
                'slug' => $slug,
                'name' => sprintf(
                    'Emplacements de sorts de niveau %d',
                    $level,
                ),
                'maximum' => $maximum,
                'rechargeType' => 'long-rest',
            ];
        }

        return array_values($resources);
    }
}

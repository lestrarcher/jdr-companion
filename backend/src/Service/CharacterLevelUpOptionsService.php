<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterClass;
use App\Entity\CharacterSubclass;
use App\Enum\HitPointGainMethod;
use App\Repository\CharacterClassLevelRuleRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CharacterLevelUpOptionsService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CharacterClassLevelRuleRepository $levelRuleRepository,
        private CharacterMulticlassEligibilityService $multiclassEligibility,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(Character $character): array
    {
        $classes = ReferenceVisibility::choices($this->entityManager, CharacterClass::class, $character->getCampaign()->getOwner());
        // Keep official prerequisite explanations, but do not disclose unusable personal choices.
        $classes = array_values(array_filter($classes, fn ($class) => $class->getOrigin() === \App\Enum\ReferenceOrigin::Official
            || $this->multiclassEligibility->canTakeLevel($character, $class)));
        $options = $character->getTotalLevel() >= 20 ? [] : array_map(fn ($class) => $this->serializeClassOption($character, $class), $classes);
        $needsAdvancement = count(array_filter($options, static fn ($option) => $option['eligible'] && $option['advancementRequired'])) > 0;
        $feats = $needsAdvancement ? ReferenceVisibility::choices($this->entityManager, \App\Entity\Feat::class, $character->getCampaign()->getOwner()) : [];
        $feats = array_values(array_filter($feats, static fn ($feat) => $feat->isRepeatable() || !$character->hasFeat($feat)));

        return [
            'abilities' => $needsAdvancement ? array_map(static fn (\App\Enum\Ability $ability) => ['value' => $ability->value, 'label' => $ability->label(), 'abbreviation' => $ability->abbreviation()], \App\Enum\Ability::cases()) : [],
            'feats' => array_map(static fn (\App\Entity\Feat $feat) => [
                'id' => $feat->getId(), 'slug' => $feat->getSlug(), 'name' => $feat->getName(), 'description' => $feat->getDescription(),
                'repeatable' => $feat->isRepeatable(), 'requiresAbilityChoice' => $feat->requiresAbilityChoice(),
                'chosenAbilityIncrease' => $feat->getChosenAbilityIncrease(),
                'allowedAbilities' => array_map(static fn ($ability) => $ability->value, $feat->getAllowedAbilities()),
            ], $feats),
            'canLevelUp' => $character->getTotalLevel() < 20,
            'currentTotalLevel' => $character->getTotalLevel(),
            'nextTotalLevel' => min(20, $character->getTotalLevel() + 1),
            'hitPointMethods' => [
                [
                    'value' => HitPointGainMethod::Average->value,
                    'label' => HitPointGainMethod::Average->label(),
                ],
                [
                    'value' => HitPointGainMethod::Rolled->value,
                    'label' => HitPointGainMethod::Rolled->label(),
                ],
                [
                    'value' => HitPointGainMethod::Manual->value,
                    'label' => HitPointGainMethod::Manual->label(),
                ],
            ],
            'classes' => $options,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeClassOption(
        Character $character,
        CharacterClass $characterClass,
    ): array {
        $currentClassLevel =
            $character->getLevelInClass($characterClass);

        $nextClassLevel = $currentClassLevel + 1;

        $currentSubclass =
            $character->getSubclassFor($characterClass);

        $selectionLevel =
            $characterClass->getSubclassSelectionLevel();

        $subclassRequired =
            $currentSubclass === null
            && $nextClassLevel >= $selectionLevel;

        $eligible = $this->multiclassEligibility->canTakeLevel($character, $characterClass);
        $subclasses = $subclassRequired && $eligible
            ? ReferenceVisibility::choices($this->entityManager, CharacterSubclass::class, $character->getCampaign()->getOwner(), ['characterClass' => $characterClass]) : [];
        if ($currentSubclass !== null && !ReferenceVisibility::allows($currentSubclass, $character->getCampaign()->getOwner())) {
            throw new \DomainException('Une acquisition existante est hors du catalogue du personnage.');
        }

        $levelRule =
            $this->levelRuleRepository->findForClassLevel(
                $characterClass,
                $nextClassLevel,
            );

        return [
            'id' => $characterClass->getId(),
            'slug' => $characterClass->getSlug(),
            'name' => $characterClass->getName(),
            'hitDie' => $characterClass->getHitDie(),
            'averageHitPointGain' =>
                intdiv($characterClass->getHitDie(), 2) + 1,
            'currentLevel' => $currentClassLevel,
            'nextLevel' => $nextClassLevel,
            'subclassSelectionLevel' => $selectionLevel,
            'subclassRequired' => $subclassRequired,
            'currentSubclass' => $currentSubclass !== null
                ? [
                    'id' => $currentSubclass->getId(),
                    'slug' => $currentSubclass->getSlug(),
                    'name' => $currentSubclass->getName(),
                ]
                : null,
            'subclasses' => array_map(
                static fn (
                    CharacterSubclass $subclass,
                ): array => [
                    'id' => $subclass->getId(),
                    'slug' => $subclass->getSlug(),
                    'name' => $subclass->getName(),
                ],
                $subclasses,
            ),
            'advancementRequired' =>
                $levelRule
                    ?->requiresAbilityScoreImprovementOrFeat()
                ?? false,
            'eligible' => $this->multiclassEligibility->canTakeLevel(
                $character,
                $characterClass,
            ),
            'multiclassRequirements' =>
                $this->multiclassEligibility->missingRequirements(
                    $character,
                    $characterClass,
                ),
        ];
    }
}

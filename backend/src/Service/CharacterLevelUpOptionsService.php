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
        $classes = $this->entityManager
            ->getRepository(CharacterClass::class)
            ->findBy([], ['name' => 'ASC']);

        return [
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
            'classes' => array_map(
                fn (CharacterClass $characterClass): array =>
                    $this->serializeClassOption(
                        $character,
                        $characterClass,
                    ),
                $classes,
            ),
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

        $subclasses = $this->entityManager
            ->getRepository(CharacterSubclass::class)
            ->findBy(
                ['characterClass' => $characterClass],
                ['name' => 'ASC'],
            );

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

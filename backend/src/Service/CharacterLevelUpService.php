<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\LevelAdvancementSelection;
use App\Entity\Character;
use App\Entity\CharacterAbilityAdjustment;
use App\Entity\CharacterClass;
use App\Entity\CharacterClassLevel;
use App\Entity\CharacterFeat;
use App\Entity\CharacterSubclass;
use App\Entity\CharacterSessionState;
use Doctrine\DBAL\LockMode;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use App\Enum\AbilityAdjustmentOperation;
use App\Enum\AbilityAdjustmentSource;
use App\Enum\HitPointGainMethod;
use App\Repository\CharacterClassLevelRuleRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CharacterLevelUpService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CharacterClassLevelRuleRepository $levelRuleRepository,
        private CharacterSessionStateSynchronizer $stateSynchronizer,
        private CharacterMulticlassEligibilityService $multiclassEligibility,
        private CharacterAbilityCalculator $abilityCalculator,
    ) {
    }

    public function levelUp(
        Character $character,
        CharacterClass $characterClass,
        ?CharacterSubclass $subclass = null,
        ?LevelAdvancementSelection $advancement = null,
        ?HitPointGainMethod $hitPointGainMethod = null,
        ?int $hitPointGain = null,
        ?CharacterSessionState $authorization = null,
    ): CharacterClassLevel {
        return $this->entityManager->wrapInTransaction(function () use (
            $character,
            $characterClass,
            $subclass,
            $advancement,
            $hitPointGainMethod,
            $hitPointGain,
            $authorization,
        ): CharacterClassLevel {
            if ($authorization !== null) {
                if ($authorization->getCharacter() !== $character) {
                    throw new AccessDeniedHttpException('Autorisation pour un autre personnage.');
                }
                PlayerCharacterAccess::lockForMutation($this->entityManager, $authorization);
                if (!$authorization->isLevelUpAllowed()) {
                    throw new AccessDeniedHttpException('La montée de niveau n’a pas été autorisée par le MJ.');
                }
            } elseif ($character->getId() !== null) {
                $this->entityManager->lock($character, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($character);
            }
            $this->validateCharacterCanLevelUp($character);
            $this->validateMulticlassEligibility(
                $character,
                $characterClass,
            );

            $stateBeforeLevelUp =
                $this->stateSynchronizer->snapshotForLevelUp($character);

            $totalLevel = $character->getNextLevelPosition();
            $classLevel = $character->getLevelInClass($characterClass) + 1;
            $subclass = $this->resolveSubclass(
                $character,
                $characterClass,
                $classLevel,
                $subclass,
            );

            $this->validateAdvancementChoice(
                $characterClass,
                $classLevel,
                $advancement,
            );

            [$resolvedHitPointGain, $resolvedHitPointGainMethod] =
                $this->resolveHitPointGain(
                    $characterClass,
                    $totalLevel,
                    $hitPointGainMethod,
                    $hitPointGain,
                );

            if ($advancement !== null) {
                $this->applyAdvancementChoice($character, $advancement, $totalLevel);
            }

            $level = new CharacterClassLevel(
                character: $character,
                characterClass: $characterClass,
                position: $totalLevel,
                subclass: $subclass,
                hitPointGain: $resolvedHitPointGain,
                hitPointGainMethod: $resolvedHitPointGainMethod,
            );

            $character->addClassLevel($level);
            $this->entityManager->persist($level);

            $this->stateSynchronizer->synchronizeAfterLevelUp(
                $character,
                $stateBeforeLevelUp,
            );

            if ($authorization !== null) {
                $authorization->setLevelUpAllowed(false);
            }

            $this->entityManager->flush();

            return $level;
        });
    }

    private function validateCharacterCanLevelUp(Character $character): void
    {
        if ($character->getTotalLevel() >= 20) {
            throw new \DomainException(
                'Ce personnage a déjà atteint le niveau maximum.',
            );
        }
    }

    private function validateMulticlassEligibility(
        Character $character,
        CharacterClass $characterClass,
    ): void {
        if (
            $this->multiclassEligibility->canTakeLevel(
                $character,
                $characterClass,
            )
        ) {
            return;
        }

        $requirements =
            $this->multiclassEligibility->missingRequirements(
                $character,
                $characterClass,
            );

        throw new \DomainException(sprintf(
            'Le personnage ne remplit pas les prérequis pour se multiclasser en %s : %s.',
            $characterClass->getName(),
            implode(' ; ', $requirements),
        ));
    }

    private function validateAdvancementChoice(
        CharacterClass $characterClass,
        int $classLevel,
        ?LevelAdvancementSelection $advancement,
    ): void {
        $rule = $this->levelRuleRepository->findForClassLevel(
            $characterClass,
            $classLevel,
        );

        $requiresAdvancement =
            $rule?->requiresAbilityScoreImprovementOrFeat() ?? false;

        if ($requiresAdvancement && $advancement === null) {
            throw new \DomainException(sprintf(
                'Le niveau %d de %s nécessite de choisir un don ou une augmentation de caractéristiques.',
                $classLevel,
                $characterClass->getName(),
            ));
        }

        if (!$requiresAdvancement && $advancement !== null) {
            throw new \DomainException(sprintf(
                'Le niveau %d de %s ne permet pas de choisir un don ou une augmentation de caractéristiques.',
                $classLevel,
                $characterClass->getName(),
            ));
        }
    }

    /**
     * @return array{int, HitPointGainMethod}
     */
    private function resolveHitPointGain(
        CharacterClass $characterClass,
        int $totalLevel,
        ?HitPointGainMethod $method,
        ?int $gain,
    ): array {
        if ($totalLevel === 1) {
            if (
                $method !== null
                && $method !== HitPointGainMethod::FirstLevel
            ) {
                throw new \DomainException(
                    'Le premier niveau utilise obligatoirement le maximum du dé de vie.',
                );
            }

            if ($gain !== null && $gain !== $characterClass->getHitDie()) {
                throw new \DomainException(
                    'Le gain de PV du premier niveau doit correspondre au maximum du dé de vie.',
                );
            }

            return [
                $characterClass->getHitDie(),
                HitPointGainMethod::FirstLevel,
            ];
        }

        $method ??= HitPointGainMethod::Average;

        if ($method === HitPointGainMethod::FirstLevel) {
            throw new \DomainException(
                'La méthode du premier niveau ne peut pas être utilisée après le niveau 1.',
            );
        }

        if ($method === HitPointGainMethod::Average) {
            $average = intdiv($characterClass->getHitDie(), 2) + 1;

            if ($gain !== null && $gain !== $average) {
                throw new \DomainException(sprintf(
                    'La valeur moyenne d’un d%d est %d.',
                    $characterClass->getHitDie(),
                    $average,
                ));
            }

            return [$average, $method];
        }

        if ($gain === null) {
            throw new \DomainException(
                'Le résultat du dé de vie doit être renseigné.',
            );
        }

        if ($gain < 1 || $gain > $characterClass->getHitDie()) {
            throw new \DomainException(sprintf(
                'Le gain brut de PV doit être compris entre 1 et %d.',
                $characterClass->getHitDie(),
            ));
        }

        return [$gain, $method];
    }

    private function applyAdvancementChoice(
        Character $character,
        LevelAdvancementSelection $advancement,
        int $totalLevel,
    ): void {
        if ($advancement->isFeat()) {
            $this->applyFeat($character, $advancement, $totalLevel);

            return;
        }

        // Validate the entire split before adding or persisting any adjustment.
        foreach ($advancement->getAbilityIncreases() as $increase) {
            $ability = $increase['ability'];
            $maximum = $character->getAbilityScore($ability)->getMaximumValue();
            $current = $this->abilityCalculator->calculatePermanentValue($character, $ability);
            if ($current + $increase['value'] > $maximum) {
                throw new \DomainException(sprintf(
                    'L’augmentation de %s dépasserait son maximum de %d.',
                    $ability->value,
                    $maximum,
                ));
            }
        }

        foreach ($advancement->getAbilityIncreases() as $index => $increase) {
            $adjustment = new CharacterAbilityAdjustment(
                character: $character,
                ability: $increase['ability'],
                operation: AbilityAdjustmentOperation::Increase,
                value: $increase['value'],
                source: AbilityAdjustmentSource::AbilityScoreImprovement,
                label: sprintf(
                    'Augmentation de caractéristiques — niveau %d',
                    $totalLevel,
                ),
                acquiredAtLevel: $totalLevel,
                displayOrder: ($totalLevel * 10) + $index,
            );

            $character->addAbilityAdjustment($adjustment);
            $this->entityManager->persist($adjustment);
        }
    }

    private function applyFeat(
        Character $character,
        LevelAdvancementSelection $advancement,
        int $totalLevel,
    ): void {
        $feat = $advancement->getFeat();

        if ($feat === null) {
            throw new \LogicException(
                'Le choix de progression ne contient aucun don.',
            );
        }

        if (!$feat->isRepeatable() && $character->hasFeat($feat)) {
            throw new \DomainException(sprintf(
                'Le personnage possède déjà le don "%s".',
                $feat->getName(),
            ));
        }

        $characterFeat = (new CharacterFeat($character, $feat))
            ->setChosenAbility($advancement->getFeatAbility())
            ->setAcquiredAtLevel($totalLevel);

        if (!$characterFeat->hasRequiredAbilityChoice()) {
            throw new \DomainException(sprintf(
                'Le don "%s" nécessite de choisir une caractéristique.',
                $feat->getName(),
            ));
        }

        $character->addFeat($characterFeat);
        $this->entityManager->persist($characterFeat);
    }

    private function resolveSubclass(
        Character $character,
        CharacterClass $characterClass,
        int $newClassLevel,
        ?CharacterSubclass $requestedSubclass,
    ): ?CharacterSubclass {
        $selectionLevel = $characterClass->getSubclassSelectionLevel();
        $currentSubclass = $character->getSubclassFor($characterClass);

        if (
            $requestedSubclass !== null
            && $requestedSubclass->getCharacterClass() !== $characterClass
        ) {
            throw new \DomainException(
                'Cette sous-classe n’appartient pas à la classe sélectionnée.',
            );
        }

        if ($newClassLevel < $selectionLevel) {
            if ($requestedSubclass !== null) {
                throw new \DomainException(sprintf(
                    'La sous-classe de %s ne peut être choisie qu’au niveau %d de cette classe.',
                    $characterClass->getName(),
                    $selectionLevel,
                ));
            }

            return null;
        }

        if ($currentSubclass !== null) {
            if (
                $requestedSubclass !== null
                && $requestedSubclass !== $currentSubclass
            ) {
                throw new \DomainException(
                    'La sous-classe choisie ne peut pas être remplacée pendant une montée de niveau.',
                );
            }

            return $currentSubclass;
        }

        if ($requestedSubclass === null) {
            throw new \DomainException(sprintf(
                'Une sous-classe de %s doit être choisie pour atteindre le niveau %d.',
                $characterClass->getName(),
                $newClassLevel,
            ));
        }

        return $requestedSubclass;
    }
}

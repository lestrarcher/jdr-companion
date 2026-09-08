<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\LevelAdvancementSelection;
use App\Entity\Campaign;
use App\Entity\Character;
use App\Entity\CharacterClass;
use App\Entity\CharacterSubclass;
use App\Entity\Feat;
use App\Enum\Ability;
use App\Repository\CharacterClassLevelRuleRepository;
use App\Repository\CharacterRepository;
use App\Security\Voter\CampaignVoter;
use App\Service\CharacterLevelUpService;
use App\Service\CharacterProfileSerializer;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/campaigns/{campaignId}/characters/{characterId}/level-up',
    requirements: [
        'campaignId' => '\d+',
        'characterId' => '\d+',
    ],
)]
final class CharacterLevelUpController extends AbstractController
{
    #[Route('/options', name: 'api_character_level_up_options', methods: ['GET'])]
    public function options(
        #[MapEntity(id: 'campaignId')]
        Campaign $campaign,
        int $characterId,
        CharacterRepository $characterRepository,
        CharacterClassLevelRuleRepository $levelRuleRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(CampaignVoter::MANAGE, $campaign);

        $character = $this->findCharacter(
            $campaign,
            $characterId,
            $characterRepository,
        );

        if ($character instanceof JsonResponse) {
            return $character;
        }

        $classes = $entityManager
            ->getRepository(CharacterClass::class)
            ->findBy([], ['name' => 'ASC']);

        return $this->json([
            'canLevelUp' => $character->getTotalLevel() < 20,
            'currentTotalLevel' => $character->getTotalLevel(),
            'nextTotalLevel' => min(20, $character->getTotalLevel() + 1),
            'classes' => array_map(
                function (CharacterClass $characterClass) use (
                    $character,
                    $levelRuleRepository,
                    $entityManager,
                ): array {
                    $currentClassLevel = $character->getLevelInClass($characterClass);
                    $nextClassLevel = $currentClassLevel + 1;
                    $currentSubclass = $character->getSubclassFor($characterClass);
                    $selectionLevel = $characterClass->getSubclassSelectionLevel();
                    $subclassRequired = $currentSubclass === null
                        && $nextClassLevel >= $selectionLevel;

                    $subclasses = $entityManager
                        ->getRepository(CharacterSubclass::class)
                        ->findBy(
                            ['characterClass' => $characterClass],
                            ['name' => 'ASC'],
                        );

                    $levelRule = $levelRuleRepository->findForClassLevel(
                        $characterClass,
                        $nextClassLevel,
                    );

                    return [
                        'id' => $characterClass->getId(),
                        'slug' => $characterClass->getSlug(),
                        'name' => $characterClass->getName(),
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
                            static fn (CharacterSubclass $subclass): array => [
                                'id' => $subclass->getId(),
                                'slug' => $subclass->getSlug(),
                                'name' => $subclass->getName(),
                            ],
                            $subclasses,
                        ),
                        'advancementRequired' =>
                            $levelRule?->requiresAbilityScoreImprovementOrFeat()
                            ?? false,
                    ];
                },
                $classes,
            ),
        ]);
    }

    #[Route('', name: 'api_character_level_up', methods: ['POST'])]
    public function levelUp(
        #[MapEntity(id: 'campaignId')]
        Campaign $campaign,
        int $characterId,
        Request $request,
        CharacterRepository $characterRepository,
        EntityManagerInterface $entityManager,
        CharacterLevelUpService $levelUpService,
        CharacterProfileSerializer $serializer,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(CampaignVoter::MANAGE, $campaign);

        $character = $this->findCharacter(
            $campaign,
            $characterId,
            $characterRepository,
        );

        if ($character instanceof JsonResponse) {
            return $character;
        }

        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            return $this->json(
                ['message' => 'Le corps JSON est invalide.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $classId = $this->integer($payload['classId'] ?? null);

        if ($classId === null || $classId <= 0) {
            return $this->validationError('La classe est obligatoire.');
        }

        $characterClass = $entityManager
            ->getRepository(CharacterClass::class)
            ->find($classId);

        if (!$characterClass instanceof CharacterClass) {
            return $this->validationError('La classe sélectionnée est introuvable.');
        }

        $subclass = $this->resolveSubclass(
            $payload['subclassId'] ?? null,
            $entityManager,
        );

        if ($subclass instanceof JsonResponse) {
            return $subclass;
        }

        $advancement = $this->resolveAdvancement(
            $payload['advancement'] ?? null,
            $entityManager,
        );

        if ($advancement instanceof JsonResponse) {
            return $advancement;
        }

        try {
            $level = $levelUpService->levelUp(
                character: $character,
                characterClass: $characterClass,
                subclass: $subclass,
                advancement: $advancement,
            );
        } catch (\LogicException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->json(
            [
                'message' => sprintf(
                    '%s atteint le niveau %d de %s.',
                    $character->getName(),
                    $character->getLevelInClass($characterClass),
                    $characterClass->getName(),
                ),
                'level' => [
                    'position' => $level->getPosition(),
                    'classId' => $level->getCharacterClass()->getId(),
                    'className' => $level->getCharacterClass()->getName(),
                    'subclassId' => $level->getSubclass()?->getId(),
                    'subclassName' => $level->getSubclass()?->getName(),
                ],
                'character' => $serializer->serialize($character),
            ],
            Response::HTTP_CREATED,
        );
    }

    private function resolveSubclass(
        mixed $subclassId,
        EntityManagerInterface $entityManager,
    ): CharacterSubclass|JsonResponse|null {
        if ($subclassId === null || $subclassId === '') {
            return null;
        }

        $subclassId = $this->integer($subclassId);

        if ($subclassId === null || $subclassId <= 0) {
            return $this->validationError('La sous-classe sélectionnée est invalide.');
        }

        $subclass = $entityManager
            ->getRepository(CharacterSubclass::class)
            ->find($subclassId);

        return $subclass instanceof CharacterSubclass
            ? $subclass
            : $this->validationError('La sous-classe sélectionnée est introuvable.');
    }

    private function resolveAdvancement(
        mixed $payload,
        EntityManagerInterface $entityManager,
    ): LevelAdvancementSelection|JsonResponse|null {
        if ($payload === null) {
            return null;
        }

        if (!is_array($payload)) {
            return $this->validationError('Le choix de progression est invalide.');
        }

        return match ($payload['type'] ?? null) {
            'ability' => $this->resolveAbilityAdvancement($payload),
            'feat' => $this->resolveFeatAdvancement($payload, $entityManager),
            default => $this->validationError(
                'Le choix doit être une augmentation de caractéristiques ou un don.',
            ),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveAbilityAdvancement(
        array $payload,
    ): LevelAdvancementSelection|JsonResponse {
        $increases = $payload['increases'] ?? null;

        if (!is_array($increases)) {
            return $this->validationError(
                'Les augmentations de caractéristiques sont invalides.',
            );
        }

        if (count($increases) === 1) {
            $increase = $increases[0] ?? null;

            if (
                !is_array($increase)
                || ($increase['value'] ?? null) !== 2
            ) {
                return $this->validationError(
                    'Une caractéristique unique doit recevoir un bonus de +2.',
                );
            }

            $ability = Ability::tryFrom((string) ($increase['ability'] ?? ''));

            return $ability !== null
                ? LevelAdvancementSelection::increaseOneAbility($ability)
                : $this->validationError('La caractéristique sélectionnée est invalide.');
        }

        if (count($increases) === 2) {
            $firstIncrease = $increases[0] ?? null;
            $secondIncrease = $increases[1] ?? null;

            if (
                !is_array($firstIncrease)
                || !is_array($secondIncrease)
                || ($firstIncrease['value'] ?? null) !== 1
                || ($secondIncrease['value'] ?? null) !== 1
            ) {
                return $this->validationError(
                    'Deux caractéristiques doivent chacune recevoir un bonus de +1.',
                );
            }

            $firstAbility = Ability::tryFrom(
                (string) ($firstIncrease['ability'] ?? ''),
            );
            $secondAbility = Ability::tryFrom(
                (string) ($secondIncrease['ability'] ?? ''),
            );

            if ($firstAbility === null || $secondAbility === null) {
                return $this->validationError(
                    'Une caractéristique sélectionnée est invalide.',
                );
            }

            try {
                return LevelAdvancementSelection::increaseTwoAbilities(
                    $firstAbility,
                    $secondAbility,
                );
            } catch (\LogicException $exception) {
                return $this->validationError($exception->getMessage());
            }
        }

        return $this->validationError(
            'Choisis une caractéristique à +2 ou deux caractéristiques à +1.',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveFeatAdvancement(
        array $payload,
        EntityManagerInterface $entityManager,
    ): LevelAdvancementSelection|JsonResponse {
        $featId = $this->integer($payload['featId'] ?? null);

        if ($featId === null || $featId <= 0) {
            return $this->validationError('Le don est obligatoire.');
        }

        $feat = $entityManager->getRepository(Feat::class)->find($featId);

        if (!$feat instanceof Feat) {
            return $this->validationError('Le don sélectionné est introuvable.');
        }

        $chosenAbility = null;

        if (($payload['ability'] ?? null) !== null) {
            $chosenAbility = Ability::tryFrom((string) $payload['ability']);

            if ($chosenAbility === null) {
                return $this->validationError(
                    'La caractéristique du don est invalide.',
                );
            }

            if (!$feat->allowsAbility($chosenAbility)) {
                return $this->validationError(
                    'Cette caractéristique n’est pas autorisée pour ce don.',
                );
            }
        }

        try {
            return LevelAdvancementSelection::feat($feat, $chosenAbility);
        } catch (\LogicException $exception) {
            return $this->validationError($exception->getMessage());
        }
    }

    private function findCharacter(
        Campaign $campaign,
        int $characterId,
        CharacterRepository $characterRepository,
    ): Character|JsonResponse {
        $character = $characterRepository->findOneBy([
            'id' => $characterId,
            'campaign' => $campaign,
        ]);

        return $character instanceof Character
            ? $character
            : $this->json(
                ['message' => 'Personnage introuvable dans cette campagne.'],
                Response::HTTP_NOT_FOUND,
            );
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) && !is_float($value)) {
            return null;
        }

        $result = filter_var($value, FILTER_VALIDATE_INT);

        return $result !== false ? $result : null;
    }

    private function validationError(string $message): JsonResponse
    {
        return $this->json(
            ['message' => $message],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

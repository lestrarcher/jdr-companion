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
use App\Enum\HitPointGainMethod;
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
use App\Service\CharacterLevelUpOptionsService;
use App\Service\CharacterLevelUpRequestResolver;

#[Route(
    '/campaigns/{campaignId}/characters/{characterId}/level-up',
    requirements: [
        'campaignId' => '\d+',
        'characterId' => '\d+',
    ],
)]
final class CharacterLevelUpController extends AbstractController
{
    #[Route(
        '/options',
        name: 'api_character_level_up_options',
        methods: ['GET'],
    )]
    public function options(
        #[MapEntity(id: 'campaignId')]
        Campaign $campaign,
        int $characterId,
        CharacterRepository $characterRepository,
        CharacterLevelUpOptionsService $optionsService,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $campaign,
        );

        $character = $this->findCharacter(
            $campaign,
            $characterId,
            $characterRepository,
        );

        if ($character instanceof JsonResponse) {
            return $character;
        }

        return $this->json(
            $optionsService->getOptions($character),
        );
    }

    #[Route('', name: 'api_character_level_up', methods: ['POST'])]
    public function levelUp(
        #[MapEntity(id: 'campaignId')] Campaign $campaign,
        int $characterId,
        Request $request,
        CharacterRepository $characterRepository,
        EntityManagerInterface $entityManager,
        CharacterLevelUpService $levelUpService,
        CharacterProfileSerializer $serializer,
        CharacterLevelUpRequestResolver $requestResolver,
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

        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            return $this->json(
                ['message' => 'Le corps JSON est invalide.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $selection = $requestResolver->resolve(
                $payload,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->validationError(
                $exception->getMessage(),
            );
        }

        $characterClass =
            $selection['characterClass'];

        try {
            $level = $levelUpService->levelUp(
                character: $character,
                characterClass: $characterClass,
                subclass: $selection['subclass'],
                advancement: $selection['advancement'],
                hitPointGainMethod:
                    $selection['hitPointGainMethod'],
                hitPointGain:
                    $selection['hitPointGain'],
            );
        } catch (\LogicException|\DomainException $exception) {
            return $this->validationError(
                $exception->getMessage(),
            );
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
                    'hitPointGain' => $level->getHitPointGain(),
                    'hitPointGainMethod' =>
                        $level->getHitPointGainMethod()?->value,
                ],
                'character' => $serializer->serialize($character),
            ],
            Response::HTTP_CREATED,
        );
    }

    /**
     * @return array{method: HitPointGainMethod, gain: int|null}|JsonResponse
     */
    private function resolveHitPoints(
        mixed $payload,
        CharacterClass $characterClass,
    ): array|JsonResponse {
        if ($payload === null) {
            return [
                'method' => HitPointGainMethod::Average,
                'gain' => null,
            ];
        }

        if (!is_array($payload)) {
            return $this->validationError(
                'Le choix des points de vie est invalide.',
            );
        }

        $method = HitPointGainMethod::tryFrom(
            (string) ($payload['method'] ?? ''),
        );

        if (
            $method === null
            || $method === HitPointGainMethod::FirstLevel
        ) {
            return $this->validationError(
                'La méthode de gain de points de vie est invalide.',
            );
        }

        if ($method === HitPointGainMethod::Average) {
            return [
                'method' => $method,
                'gain' => null,
            ];
        }

        $gain = $this->integer($payload['gain'] ?? null);

        if (
            $gain === null
            || $gain < 1
            || $gain > $characterClass->getHitDie()
        ) {
            return $this->validationError(sprintf(
                'Le gain brut de PV doit être compris entre 1 et %d.',
                $characterClass->getHitDie(),
            ));
        }

        return [
            'method' => $method,
            'gain' => $gain,
        ];
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
            return $this->validationError(
                'La sous-classe sélectionnée est invalide.',
            );
        }

        $subclass = $entityManager
            ->getRepository(CharacterSubclass::class)
            ->find($subclassId);

        return $subclass instanceof CharacterSubclass
            ? $subclass
            : $this->validationError(
                'La sous-classe sélectionnée est introuvable.',
            );
    }

    private function resolveAdvancement(
        mixed $payload,
        EntityManagerInterface $entityManager,
    ): LevelAdvancementSelection|JsonResponse|null {
        if ($payload === null) {
            return null;
        }

        if (!is_array($payload)) {
            return $this->validationError(
                'Le choix de progression est invalide.',
            );
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

            $ability = Ability::tryFrom(
                (string) ($increase['ability'] ?? ''),
            );

            return $ability !== null
                ? LevelAdvancementSelection::increaseOneAbility($ability)
                : $this->validationError(
                    'La caractéristique sélectionnée est invalide.',
                );
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

        $feat = $entityManager
            ->getRepository(Feat::class)
            ->find($featId);

        if (!$feat instanceof Feat) {
            return $this->validationError(
                'Le don sélectionné est introuvable.',
            );
        }

        $chosenAbility = null;

        if (($payload['ability'] ?? null) !== null) {
            $chosenAbility = Ability::tryFrom(
                (string) $payload['ability'],
            );

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
            return LevelAdvancementSelection::feat(
                $feat,
                $chosenAbility,
            );
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

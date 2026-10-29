<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CharacterSessionState;
use App\Entity\GameSession;
use App\Enum\CharacterActionHandlerType;
use App\Service\CharacterAction\CharacterFlexibleCastingActionService;
use App\Repository\CharacterSessionStateRepository;
use App\Service\CharacterAction\CharacterArcaneRecoveryActionService;
use App\Service\CharacterActionResolver;
use App\Service\CharacterSessionStateSerializer;
use App\Service\PlayerCharacterAccess;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CharacterActionController extends AbstractController
{
    public function __construct(
        private readonly CharacterSessionStateSerializer $sessionStateSerializer,
    ) {
    }

    #[Route('/public/characters/{accessToken}/actions/prepared', name: 'api_public_character_actions_prepared_update', requirements: ['accessToken' => '[a-f0-9]{64}'], methods: ['PATCH'])]
    public function updatePreparedActions(
        string $accessToken,
        Request $request,
        PlayerCharacterAccess $playerAccess,
        CharacterActionResolver $actionResolver,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $sessionState = $playerAccess->requireParticipating($accessToken);

        if ($sessionState->getGameSession()->getStatus() !== GameSession::STATUS_LIVE) {
            return $this->json(['message' => 'Cette session n’est pas ouverte.'], Response::HTTP_CONFLICT);
        }

        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            return $this->json(['message' => 'Le corps JSON est invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $prepared = $payload['prepared'] ?? null;
        $revision = $payload['revision'] ?? null;

        if (!is_array($prepared)) {
            return $this->json(['message' => 'La propriété "prepared" doit être un tableau.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!is_int($revision) || $revision < 1) {
            return $this->json(['message' => 'Une révision entière positive est requise.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach ($prepared as $slug) {
            if (!is_string($slug) || $slug === '') {
                return $this->json(['message' => 'Chaque action préparée doit être identifiée par un slug valide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $prepared = array_values(array_unique($prepared));
        $eligibleActions = $actionResolver->resolve($sessionState->getCharacter());

        foreach ($prepared as $slug) {
            $action = $eligibleActions[$slug] ?? null;

            if ($action === null || !$action->requiresPreparation()) {
                return $this->json(['message' => sprintf('L’action "%s" ne peut pas être préparée par ce personnage.', $slug)], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if ($sessionState->getRevision() !== $revision) {
            return $this->json(['message' => 'Le personnage a été modifié ailleurs. Rechargez son état.'], Response::HTTP_CONFLICT);
        }

        try {
            return $entityManager->wrapInTransaction(function () use ($entityManager, $sessionState, $revision, $prepared): JsonResponse {
                $entityManager->lock($sessionState->getGameSession(), LockMode::PESSIMISTIC_READ);
                $entityManager->refresh($sessionState->getGameSession());

                if ($sessionState->getGameSession()->getStatus() !== GameSession::STATUS_LIVE) {
                    return $this->json(['message' => 'Cette session n’est pas ouverte.'], Response::HTTP_CONFLICT);
                }

                $entityManager->lock($sessionState, LockMode::OPTIMISTIC, $revision);

                $state = $sessionState->getState();
                $characterActions = $state['characterActions'] ?? [];

                if (!is_array($characterActions)) {
                    $characterActions = [];
                }

                $characterActions['prepared'] = $prepared;
                $characterActions['preparationPending'] = false;
                $state['characterActions'] = $characterActions;
                $sessionState->setState($state);

                $entityManager->flush();

                return $this->json($this->sessionStateSerializer->serialize($sessionState));
            });
        } catch (OptimisticLockException) {
            return $this->json(['message' => 'Le personnage a été modifié ailleurs. Rechargez son état.'], Response::HTTP_CONFLICT);
        }
    }

    #[Route('/public/characters/{accessToken}/action-targets', name: 'api_public_character_action_targets', requirements: ['accessToken' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function actionTargets(
        string $accessToken,
        PlayerCharacterAccess $playerAccess,
        CharacterSessionStateRepository $stateRepository,
    ): JsonResponse {
        $characterState = $playerAccess->requireParticipating($accessToken);

        $states = $stateRepository->findBy([
            'gameSession' => $characterState->getGameSession(),
            'participating' => true,
        ]);

        return $this->json([
            'targets' => array_map(
                static fn (CharacterSessionState $state): array => [
                    'id' => $state->getCharacter()->getId(),
                    'name' => $state->getCharacter()->getName(),
                ],
                $states,
            ),
        ]);
    }

    // Tombstones for older clients: no mutation, resource consumption or automatic forwarding.
    #[Route('/public/characters/{accessToken}/actions/aid', name: 'api_public_character_action_aid', requirements: ['accessToken' => '[a-f0-9]{64}'], methods: ['POST'])]
    #[Route('/public/characters/{accessToken}/actions/heroes-feast', name: 'api_public_character_action_heroes_feast', requirements: ['accessToken' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function retiredHitPointSpell(string $accessToken, PlayerCharacterAccess $playerAccess): JsonResponse
    {
        $playerAccess->requireParticipating($accessToken);
        return $this->json(['message' => 'Ce parcours est retiré. Rechargez le portail et lancez ce sort depuis le répertoire des sorts.'], Response::HTTP_GONE);
    }
    #[Route('/public/characters/{accessToken}/actions/flexible-casting/create-spell-slot', name: 'api_public_character_action_flexible_casting_create', requirements: ['accessToken' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function createFlexibleCastingSlot(string $accessToken, Request $request, PlayerCharacterAccess $playerAccess, CharacterActionResolver $actionResolver, CharacterFlexibleCastingActionService $castingService, EntityManagerInterface $entityManager): JsonResponse
    {
        return $this->executeFlexibleCasting($accessToken, $request, $playerAccess, $actionResolver, $entityManager, $castingService->createSpellSlot(...));
    }

    #[Route('/public/characters/{accessToken}/actions/flexible-casting/convert-spell-slot', name: 'api_public_character_action_flexible_casting_convert', requirements: ['accessToken' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function convertFlexibleCastingSlot(string $accessToken, Request $request, PlayerCharacterAccess $playerAccess, CharacterActionResolver $actionResolver, CharacterFlexibleCastingActionService $castingService, EntityManagerInterface $entityManager): JsonResponse
    {
        return $this->executeFlexibleCasting($accessToken, $request, $playerAccess, $actionResolver, $entityManager, $castingService->convertSpellSlotToSorceryPoints(...));
    }

    #[Route('/public/characters/{accessToken}/actions/arcane-recovery', name: 'api_public_character_action_arcane_recovery', requirements: ['accessToken' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function arcaneRecovery(string $accessToken, Request $request, PlayerCharacterAccess $playerAccess, CharacterActionResolver $actionResolver, CharacterArcaneRecoveryActionService $arcaneRecoveryActionService, EntityManagerInterface $entityManager): JsonResponse
    {
        $sessionState = $playerAccess->requireParticipating($accessToken);
        if ($sessionState->getGameSession()->getStatus() !== GameSession::STATUS_LIVE) return $this->json(['message' => 'Cette session n’est pas ouverte.'], Response::HTTP_CONFLICT);

        try {
            $payload = $request->toArray();
        } catch (\Symfony\Component\HttpFoundation\Exception\JsonException) {
            return $this->json(['message' => 'Le corps JSON est invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $slots = $payload['slots'] ?? null;
        $revision = $payload['revision'] ?? null;
        if (!is_array($slots) || $slots === []) return $this->json(['message' => 'Au moins un emplacement doit être sélectionné.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        if (!is_int($revision) || $revision < 1) return $this->json(['message' => 'Une révision entière positive est requise.'], Response::HTTP_UNPROCESSABLE_ENTITY);

        $normalizedSlots = [];
        foreach ($slots as $level => $quantity) {
            if ((!is_int($level) && !ctype_digit((string) $level)) || !is_int($quantity)) return $this->json(['message' => 'Les emplacements sélectionnés sont invalides.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            $normalizedSlots[(int) $level] = $quantity;
        }

        try {
            return $entityManager->wrapInTransaction(function () use ($entityManager, $sessionState, $revision, $normalizedSlots, $actionResolver, $arcaneRecoveryActionService): JsonResponse {
                PlayerCharacterAccess::lockForMutation($entityManager, $sessionState);
                $entityManager->lock($sessionState, LockMode::OPTIMISTIC, $revision);

                $eligible = false;
                foreach ($actionResolver->resolve($sessionState->getCharacter()) as $definition) {
                    if ($definition->getHandlerType() === CharacterActionHandlerType::ArcaneRecovery) {
                        $eligible = true;
                        break;
                    }
                }
                if (!$eligible) return $this->json(['message' => 'Restauration arcanique n’est pas disponible pour ce personnage.'], Response::HTTP_UNPROCESSABLE_ENTITY);

                try {
                    $arcaneRecoveryActionService->recoverSpellSlots($sessionState, $normalizedSlots);
                } catch (\DomainException $exception) {
                    return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $entityManager->flush();

                return $this->json($this->sessionStateSerializer->serialize($sessionState));
            });
        } catch (OptimisticLockException) {
            return $this->json(['message' => 'Le personnage a été modifié ailleurs. Rechargez son état.'], Response::HTTP_CONFLICT);
        }
    }

    /** @param callable(CharacterSessionState, int): void $action */
    private function executeFlexibleCasting(string $accessToken, Request $request, PlayerCharacterAccess $playerAccess, CharacterActionResolver $actionResolver, EntityManagerInterface $entityManager, callable $action): JsonResponse
    {
        $sessionState = $playerAccess->requireParticipating($accessToken);
        if ($sessionState->getGameSession()->getStatus() !== GameSession::STATUS_LIVE) {
            return $this->json(['message' => 'Cette session n’est pas ouverte.'], Response::HTTP_CONFLICT);
        }

        try {
            $payload = $request->toArray();
        } catch (\Symfony\Component\HttpFoundation\Exception\JsonException) {
            return $this->json(['message' => 'Le corps JSON est invalide.'], Response::HTTP_BAD_REQUEST);
        }
        $level = $payload['level'] ?? null;
        $revision = $payload['revision'] ?? null;
        if (!is_int($level)) {
            return $this->json(['message' => 'Le niveau d’emplacement doit être un entier.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!is_int($revision) || $revision < 1) {
            return $this->json(['message' => 'Une révision entière positive est requise.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            return $entityManager->wrapInTransaction(function () use ($entityManager, $sessionState, $revision, $level, $actionResolver, $action): JsonResponse {
                PlayerCharacterAccess::lockForMutation($entityManager, $sessionState);
                $entityManager->lock($sessionState, LockMode::OPTIMISTIC, $revision);

                $eligible = false;
                foreach ($actionResolver->resolve($sessionState->getCharacter()) as $definition) {
                    if ($definition->getHandlerType() === CharacterActionHandlerType::FlexibleCasting) {
                        $eligible = true;
                        break;
                    }
                }
                if (!$eligible) {
                    return $this->json(['message' => 'Conversion flexible n’est pas disponible pour ce personnage.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                // The service validates before its single mutation; domain refusals have no writes.
                try {
                    $action($sessionState, $level);
                } catch (\DomainException $exception) {
                    return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $entityManager->flush();

                return $this->json($this->sessionStateSerializer->serialize($sessionState));
            });
        } catch (OptimisticLockException) {
            return $this->json(['message' => 'Le personnage a été modifié ailleurs. Rechargez son état.'], Response::HTTP_CONFLICT);
        }
    }

}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CharacterSessionState;
use App\Entity\GameSession;
use App\Enum\CharacterActionHandlerType;
use App\Service\CharacterAction\CharacterFlexibleCastingActionService;
use App\Repository\CharacterSessionStateRepository;
use App\Service\CharacterAction\CharacterAidActionService;
use App\Service\CharacterAction\CharacterHeroesFeastActionService;
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

    #[Route('/public/characters/{accessToken}/actions/aid', name: 'api_public_character_action_aid', requirements: ['accessToken' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function useAid(
        string $accessToken,
        Request $request,
        PlayerCharacterAccess $playerAccess,
        CharacterSessionStateRepository $stateRepository,
        CharacterAidActionService $aidActionService,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $casterState = $playerAccess->requireParticipating($accessToken);

        if ($casterState->getGameSession()->getStatus() !== GameSession::STATUS_LIVE) {
            return $this->json(['message' => 'Cette session n’est pas ouverte.'], Response::HTTP_CONFLICT);
        }

        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            return $this->json(['message' => 'Le corps JSON est invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $spellSlotLevel = $payload['spellSlotLevel'] ?? null;
        $targetIds = $payload['targetIds'] ?? null;
        $revision = $payload['revision'] ?? null;

        if (!is_int($spellSlotLevel) || $spellSlotLevel < 2) {
            return $this->json(['message' => 'Le niveau d’emplacement est invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validationError = $this->validateTargets($targetIds, 3, 'Aide');

        if ($validationError !== null) {
            return $validationError;
        }

        if (!is_int($revision) || $revision < 1) {
            return $this->json(['message' => 'Une révision entière positive est requise.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($casterState->getRevision() !== $revision) {
            return $this->json(['message' => 'Le personnage a été modifié ailleurs. Rechargez son état.'], Response::HTTP_CONFLICT);
        }

        return $this->executeHitPointBoostAction(
            casterState: $casterState,
            targetIds: array_values(array_unique($targetIds)),
            revision: $revision,
            stateRepository: $stateRepository,
            entityManager: $entityManager,
            action: fn (array $targetStates) => $aidActionService->apply($casterState, $spellSlotLevel, $targetStates),
        );
    }

    #[Route('/public/characters/{accessToken}/actions/heroes-feast', name: 'api_public_character_action_heroes_feast', requirements: ['accessToken' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function useHeroesFeast(
        string $accessToken,
        Request $request,
        PlayerCharacterAccess $playerAccess,
        CharacterSessionStateRepository $stateRepository,
        CharacterHeroesFeastActionService $heroesFeastActionService,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $casterState = $playerAccess->requireParticipating($accessToken);

        if ($casterState->getGameSession()->getStatus() !== GameSession::STATUS_LIVE) {
            return $this->json(['message' => 'Cette session n’est pas ouverte.'], Response::HTTP_CONFLICT);
        }

        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            return $this->json(['message' => 'Le corps JSON est invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $hitPointBonus = $payload['hitPointBonus'] ?? null;
        $targetIds = $payload['targetIds'] ?? null;
        $revision = $payload['revision'] ?? null;

        if (!is_int($hitPointBonus) || $hitPointBonus < 2 || $hitPointBonus > 20) {
            return $this->json(['message' => 'Le résultat des 2d10 doit être compris entre 2 et 20.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validationError = $this->validateTargets($targetIds, 12, 'Festin des héros');

        if ($validationError !== null) {
            return $validationError;
        }

        if (!is_int($revision) || $revision < 1) {
            return $this->json(['message' => 'Une révision entière positive est requise.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($casterState->getRevision() !== $revision) {
            return $this->json(['message' => 'Le personnage a été modifié ailleurs. Rechargez son état.'], Response::HTTP_CONFLICT);
        }

        return $this->executeHitPointBoostAction(
            casterState: $casterState,
            targetIds: array_values(array_unique($targetIds)),
            revision: $revision,
            stateRepository: $stateRepository,
            entityManager: $entityManager,
            action: fn (array $targetStates) => $heroesFeastActionService->apply($casterState, $hitPointBonus, $targetStates),
        );
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

    private function validateTargets(mixed $targetIds, int $maximumTargets, string $actionName): ?JsonResponse
    {
        if (!is_array($targetIds) || $targetIds === [] || count($targetIds) > $maximumTargets) {
            return $this->json(['message' => sprintf('%s doit cibler entre une et %d créatures.', $actionName, $maximumTargets)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach ($targetIds as $targetId) {
            if (!is_int($targetId)) {
                return $this->json(['message' => 'Les identifiants de cibles sont invalides.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if (count(array_unique($targetIds)) !== count($targetIds)) {
            return $this->json(['message' => sprintf('%s doit cibler des créatures différentes.', $actionName)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return null;
    }

    /**
     * @param list<int> $targetIds
     * @param callable(list<CharacterSessionState>): void $action
     */
    private function executeHitPointBoostAction(
        CharacterSessionState $casterState,
        array $targetIds,
        int $revision,
        CharacterSessionStateRepository $stateRepository,
        EntityManagerInterface $entityManager,
        callable $action,
    ): JsonResponse {
        try {
            return $entityManager->wrapInTransaction(function () use ($entityManager, $casterState, $targetIds, $revision, $stateRepository, $action): JsonResponse {
                $entityManager->lock($casterState->getGameSession(), LockMode::PESSIMISTIC_READ);
                $entityManager->refresh($casterState->getGameSession());

                if ($casterState->getGameSession()->getStatus() !== GameSession::STATUS_LIVE) {
                    return $this->json(['message' => 'Cette session n’est pas ouverte.'], Response::HTTP_CONFLICT);
                }

                $entityManager->lock($casterState, LockMode::OPTIMISTIC, $revision);

                $targetStates = [];

                foreach ($targetIds as $targetId) {
                    $targetState = $stateRepository->findOneBy([
                        'gameSession' => $casterState->getGameSession(),
                        'character' => $targetId,
                        'participating' => true,
                    ]);

                    if (!$targetState instanceof CharacterSessionState) {
                        return $this->json(['message' => 'Une cible ne participe pas à cette session.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                    }

                    $entityManager->lock($targetState, LockMode::PESSIMISTIC_WRITE);
                    $targetStates[] = $targetState;
                }

                $action($targetStates);
                $entityManager->flush();

                return $this->json($this->sessionStateSerializer->serialize($casterState));
            });
        } catch (OptimisticLockException) {
            return $this->json(['message' => 'Le personnage a été modifié ailleurs. Rechargez son état.'], Response::HTTP_CONFLICT);
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}

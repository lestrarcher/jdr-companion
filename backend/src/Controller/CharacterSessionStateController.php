<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PlayerCharacterAccess;
use App\Service\PlayerCharacterStateUpdater;
use App\Entity\CharacterActiveEffect;
use App\Service\CharacterActiveEffectService;
use App\Entity\Character;
use App\Entity\CharacterSessionState;
use App\Entity\GameSession;
use App\Repository\CharacterRepository;
use App\Repository\CharacterSessionStateRepository;
use App\Repository\GameSessionRepository;
use App\Security\Voter\CampaignVoter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\OptimisticLockException;
use App\Service\CharacterHitPointStateService;
use App\Service\CharacterActionResolver;
use App\Repository\CharacterActiveEffectRepository;
use JsonException;
use App\Service\CharacterSessionStateFactory;
use App\Service\CharacterSessionStateSynchronizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\CharacterProfileSerializer;
use App\Service\CharacterLevelUpOptionsService;
use App\Service\CharacterLevelUpRequestResolver;
use App\Service\CharacterLevelUpService;
use App\Entity\ProgressionDefinition;
use App\Service\CharacterAidActionService;
use App\Repository\ProgressionDefinitionRepository;

final class CharacterSessionStateController extends AbstractController
{

    public function __construct(
        private readonly CharacterProfileSerializer $profileSerializer,
        private readonly CharacterSessionStateSynchronizer $stateSynchronizer,
        private readonly CharacterHitPointStateService $hitPointStateService,
        private readonly CharacterActiveEffectRepository $activeEffectRepository,
    ) {
    }

    #[Route(
        '/sessions/{sessionId}/characters',
        name: 'api_character_session_state_list',
        requirements: ['sessionId' => '\d+'],
        methods: ['GET'],
    )]
    public function list(
        int $sessionId,
        GameSessionRepository $gameSessionRepository,
        CharacterSessionStateRepository $stateRepository,
    ): JsonResponse {
        $gameSession = $gameSessionRepository->find($sessionId);

        if (!$gameSession instanceof GameSession) {
            return $this->json(
                ['message' => 'Session introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $gameSession->getCampaign(),
        );

        $states = $stateRepository->findBy(
            ['gameSession' => $gameSession],
            ['id' => 'ASC'],
        );

        return $this->json([
            'states' => array_map(
                fn (CharacterSessionState $state): array =>
                    $this->serializeState($state, true),
                $states,
            ),
        ]);
    }

    #[Route(
        '/public/characters/{accessToken}/actions/prepared',
        name: 'api_public_character_actions_prepared_update',
        requirements: ['accessToken' => '[a-f0-9]{64}'],
        methods: ['PATCH'],
    )]
    public function updatePreparedActions(
        string $accessToken,
        Request $request,
        PlayerCharacterAccess $playerAccess,
        CharacterActionResolver $actionResolver,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $sessionState = $playerAccess->requireParticipating($accessToken);

        if (
            $sessionState->getGameSession()->getStatus()
            !== GameSession::STATUS_LIVE
        ) {
            return $this->json(
                ['message' => 'Cette session n’est pas ouverte.'],
                Response::HTTP_CONFLICT,
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

        $prepared = $payload['prepared'] ?? null;
        $revision = $payload['revision'] ?? null;

        if (!is_array($prepared)) {
            return $this->json(
                ['message' => 'La propriété "prepared" doit être un tableau.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (!is_int($revision) || $revision < 1) {
            return $this->json(
                ['message' => 'Une révision entière positive est requise.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        foreach ($prepared as $slug) {
            if (!is_string($slug) || $slug === '') {
                return $this->json(
                    ['message' => 'Chaque action préparée doit être identifiée par un slug valide.'],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        $prepared = array_values(array_unique($prepared));

        $eligibleActions = $actionResolver->resolve(
            $sessionState->getCharacter(),
        );

        foreach ($prepared as $slug) {
            $action = $eligibleActions[$slug] ?? null;

            if (
                $action === null
                || !$action->requiresPreparation()
            ) {
                return $this->json(
                    [
                        'message' => sprintf(
                            'L’action "%s" ne peut pas être préparée par ce personnage.',
                            $slug,
                        ),
                    ],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        if ($sessionState->getRevision() !== $revision) {
            return $this->json(
                [
                    'message' =>
                        'Le personnage a été modifié ailleurs. Rechargez son état.',
                ],
                Response::HTTP_CONFLICT,
            );
        }

        try {
            return $entityManager->wrapInTransaction(
                function () use (
                    $entityManager,
                    $sessionState,
                    $revision,
                    $prepared,
                ): JsonResponse {
                    $entityManager->lock(
                        $sessionState->getGameSession(),
                        \Doctrine\DBAL\LockMode::PESSIMISTIC_READ,
                    );

                    $entityManager->refresh(
                        $sessionState->getGameSession(),
                    );

                    if (
                        $sessionState->getGameSession()->getStatus()
                        !== GameSession::STATUS_LIVE
                    ) {
                        return $this->json(
                            ['message' => 'Cette session n’est pas ouverte.'],
                            Response::HTTP_CONFLICT,
                        );
                    }

                    $entityManager->lock(
                        $sessionState,
                        \Doctrine\DBAL\LockMode::OPTIMISTIC,
                        $revision,
                    );

                    $state = $sessionState->getState();

                    $characterActions =
                        $state['characterActions'] ?? [];

                    if (!is_array($characterActions)) {
                        $characterActions = [];
                    }

                    $characterActions['prepared'] = $prepared;
                    $characterActions['preparationPending'] = false;

                    $state['characterActions'] = $characterActions;

                    $sessionState->setState($state);

                    $entityManager->flush();

                    return $this->json(
                        $this->serializeState($sessionState),
                    );
                },
            );
        } catch (\Doctrine\ORM\OptimisticLockException) {
            return $this->json(
                [
                    'message' =>
                        'Le personnage a été modifié ailleurs. Rechargez son état.',
                ],
                Response::HTTP_CONFLICT,
            );
        }
    }
    /**
     * Crée ou réactive l’état d’un personnage pour une session.
     *
     * Cette route est réservée au MJ authentifié.
     */
    #[Route(
        '/sessions/{sessionId}/characters/{characterId}',
        name: 'api_character_session_state_create',
        requirements: [
            'sessionId' => '\d+',
            'characterId' => '\d+',
        ],
        methods: ['POST'],
    )]
    public function create(
        int $sessionId,
        int $characterId,
        GameSessionRepository $gameSessionRepository,
        CharacterRepository $characterRepository,
        CharacterSessionStateRepository $stateRepository,
        CharacterSessionStateFactory $stateFactory,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $gameSession = $gameSessionRepository->find($sessionId);
        $character = $characterRepository->find($characterId);

        if (!$gameSession instanceof GameSession) {
            return $this->json(
                ['message' => 'Session introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        if (!$character instanceof Character) {
            return $this->json(
                ['message' => 'Personnage introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $gameSession->getCampaign(),
        );

        if (
            $gameSession->getCampaign()->getId()
            !== $character->getCampaign()->getId()
        ) {
            return $this->json(
                ['message' => 'Le personnage ne fait pas partie de cette campagne.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $existingState = $stateRepository->findOneBy([
            'gameSession' => $gameSession,
            'character' => $character,
        ]);

        if ($existingState instanceof CharacterSessionState) {
            $existingState->setParticipating(true);
            $entityManager->flush();

            return $this->json(
                $this->serializeState($existingState, true),
                Response::HTTP_OK,
            );
        }

        try {
            $initialState = $stateFactory->create($character);
        } catch (\DomainException $exception) {
            return $this->json(
                ['message' => $exception->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $state = new CharacterSessionState(
            $gameSession,
            $character,
            $initialState,
        );

        $entityManager->persist($state);
        $entityManager->flush();

        return $this->json(
            $this->serializeState($state, true),
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/sessions/{sessionId}/characters/{characterId}',
        name: 'api_character_session_state_remove',
        requirements: [
            'sessionId' => '\d+',
            'characterId' => '\d+',
        ],
        methods: ['DELETE'],
    )]
    public function remove(
        int $sessionId,
        int $characterId,
        GameSessionRepository $gameSessionRepository,
        CharacterRepository $characterRepository,
        CharacterSessionStateRepository $stateRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $gameSession = $gameSessionRepository->find($sessionId);
        $character = $characterRepository->find($characterId);

        if (!$gameSession instanceof GameSession) {
            return $this->json(
                ['message' => 'Session introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        if (!$character instanceof Character) {
            return $this->json(
                ['message' => 'Personnage introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $gameSession->getCampaign(),
        );

        $state = $stateRepository->findOneBy([
            'gameSession' => $gameSession,
            'character' => $character,
        ]);

        if (!$state instanceof CharacterSessionState) {
            return $this->json(
                ['message' => 'Ce personnage ne participe pas à cette session.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $state->setParticipating(false);
        $state->setLevelUpAllowed(false);
        $entityManager->flush();

        return $this->json(
            $this->serializeState($state, true),
        );
    }

    #[Route(
        '/sessions/{sessionId}/characters/{characterId}/hit-points/maximum-adjustment',
        name: 'api_character_session_state_maximum_hit_points_adjust',
        requirements: [
            'sessionId' => '\d+',
            'characterId' => '\d+',
        ],
        methods: ['POST'],
    )]
    public function adjustMaximumHitPoints(
        int $sessionId,
        int $characterId,
        Request $request,
        GameSessionRepository $gameSessionRepository,
        CharacterRepository $characterRepository,
        CharacterSessionStateRepository $stateRepository,
        CharacterHitPointStateService $hitPointStateService,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $gameSession = $gameSessionRepository->find($sessionId);
        $character = $characterRepository->find($characterId);

        if (!$gameSession instanceof GameSession) {
            return $this->json(
                ['message' => 'Session introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        if (!$character instanceof Character) {
            return $this->json(
                ['message' => 'Personnage introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $gameSession->getCampaign(),
        );

        if (
            $gameSession->getCampaign()->getId()
            !== $character->getCampaign()->getId()
        ) {
            return $this->json(
                ['message' => 'Le personnage ne fait pas partie de cette campagne.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $sessionState = $stateRepository->findOneBy([
            'gameSession' => $gameSession,
            'character' => $character,
        ]);

        if (
            !$sessionState instanceof CharacterSessionState
            || !$sessionState->isParticipating()
        ) {
            return $this->json(
                ['message' => 'Ce personnage ne participe pas à cette session.'],
                Response::HTTP_NOT_FOUND,
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

        $delta = $payload['delta'] ?? null;

        if (!is_int($delta) || $delta === 0) {
            return $this->json(
                ['message' => 'La propriété "delta" doit être un entier non nul.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $newState = $hitPointStateService->adjustMaximum(
                $character,
                $sessionState->getState(),
                $delta,
            );

            $sessionState->setState($newState);
            $entityManager->flush();
        } catch (\DomainException $exception) {
            return $this->json(
                ['message' => $exception->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->json(
            $this->serializeState($sessionState, true),
        );
    }

    #[Route(
    '/sessions/{sessionId}/characters/{characterId}/active-effects/{effectId}',
    name: 'api_character_active_effect_terminate',
    requirements: [
        'sessionId' => '\d+',
        'characterId' => '\d+',
        'effectId' => '\d+',
    ],
    methods: ['DELETE'],
)]
public function terminateActiveEffect(
    int $sessionId,
    int $characterId,
    int $effectId,
    GameSessionRepository $gameSessionRepository,
    CharacterRepository $characterRepository,
    CharacterSessionStateRepository $stateRepository,
    CharacterActiveEffectRepository $effectRepository,
    CharacterActiveEffectService $activeEffectService,
    EntityManagerInterface $entityManager,
): JsonResponse {
    $gameSession = $gameSessionRepository->find($sessionId);
    $character = $characterRepository->find($characterId);
    $effect = $effectRepository->find($effectId);

    if (!$gameSession instanceof GameSession) {
        return $this->json(
            ['message' => 'Session introuvable.'],
            Response::HTTP_NOT_FOUND,
        );
    }

    if (!$character instanceof Character) {
        return $this->json(
            ['message' => 'Personnage introuvable.'],
            Response::HTTP_NOT_FOUND,
        );
    }

    if (!$effect instanceof CharacterActiveEffect) {
        return $this->json(
            ['message' => 'Effet actif introuvable.'],
            Response::HTTP_NOT_FOUND,
        );
    }

    $this->denyAccessUnlessGranted(
        CampaignVoter::MANAGE,
        $gameSession->getCampaign(),
    );

    if (
        $character->getCampaign()->getId()
        !== $gameSession->getCampaign()->getId()
    ) {
        return $this->json(
            ['message' => 'Le personnage ne fait pas partie de cette campagne.'],
            Response::HTTP_BAD_REQUEST,
        );
    }

    if (
        $effect->getTargetCharacter()->getId()
        !== $character->getId()
    ) {
        return $this->json(
            ['message' => 'Cet effet n’appartient pas à ce personnage.'],
            Response::HTTP_BAD_REQUEST,
        );
    }

    $sessionState = $stateRepository->findOneBy([
        'gameSession' => $gameSession,
        'character' => $character,
    ]);

    if (!$sessionState instanceof CharacterSessionState) {
        return $this->json(
            ['message' => 'État de session du personnage introuvable.'],
            Response::HTTP_NOT_FOUND,
        );
    }

    try {
        return $entityManager->wrapInTransaction(
            function () use (
                $entityManager,
                $sessionState,
                $effect,
                $activeEffectService,
            ): JsonResponse {
                $entityManager->lock(
                    $sessionState,
                    LockMode::PESSIMISTIC_WRITE,
                );

                $entityManager->lock(
                    $effect,
                    LockMode::PESSIMISTIC_WRITE,
                );

                $activeEffectService->terminate(
                    $sessionState,
                    $effect,
                );

                $entityManager->remove($effect);
                $entityManager->flush();

                return $this->json(
                    $this->serializeState(
                        $sessionState,
                        true,
                    ),
                );
            },
        );
    } catch (\DomainException $exception) {
        return $this->json(
            ['message' => $exception->getMessage()],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

    #[Route(
        '/sessions/{sessionId}/characters/{characterId}/level-up-permission',
        name: 'api_character_session_state_level_up_permission',
        requirements: [
            'sessionId' => '\d+',
            'characterId' => '\d+',
        ],
        methods: ['PATCH'],
    )]
    public function updateLevelUpPermission(
        int $sessionId,
        int $characterId,
        Request $request,
        GameSessionRepository $gameSessionRepository,
        CharacterRepository $characterRepository,
        CharacterSessionStateRepository $stateRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $gameSession = $gameSessionRepository->find($sessionId);
        $character = $characterRepository->find($characterId);

        if (!$gameSession instanceof GameSession) {
            return $this->json(
                ['message' => 'Session introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        if (!$character instanceof Character) {
            return $this->json(
                ['message' => 'Personnage introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $gameSession->getCampaign(),
        );

        $state = $stateRepository->findOneBy([
            'gameSession' => $gameSession,
            'character' => $character,
        ]);

        if (
            !$state instanceof CharacterSessionState
            || !$state->isParticipating()
        ) {
            return $this->json(
                ['message' => 'Ce personnage ne participe pas à cette session.'],
                Response::HTTP_NOT_FOUND,
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

        $allowed = $payload['allowed'] ?? null;

        if (!is_bool($allowed)) {
            return $this->json(
                ['message' => 'La propriété "allowed" doit être un booléen.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $state->setLevelUpAllowed($allowed);

        $entityManager->flush();

        return $this->json(
            $this->serializeState($state, true),
        );
    }

    #[Route(
        '/sessions/{sessionId}/characters/{characterId}/progressions/{progressionId}',
        name: 'api_character_session_state_progression_update',
        requirements: [
            'sessionId' => '\d+',
            'characterId' => '\d+',
            'progressionId' => '\d+',
        ],
        methods: ['PATCH'],
    )]
    public function updateProgression(
        int $sessionId,
        int $characterId,
        int $progressionId,
        Request $request,
        GameSessionRepository $gameSessionRepository,
        CharacterRepository $characterRepository,
        ProgressionDefinitionRepository $progressionRepository,
        CharacterSessionStateRepository $stateRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $gameSession = $gameSessionRepository->find($sessionId);
        $character = $characterRepository->find($characterId);
        $progression = $progressionRepository->find($progressionId);

        if (!$gameSession instanceof GameSession) {
            return $this->json(
                ['message' => 'Session introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        if (!$character instanceof Character) {
            return $this->json(
                ['message' => 'Personnage introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        if (!$progression instanceof ProgressionDefinition) {
            return $this->json(
                ['message' => 'Progression introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $gameSession->getCampaign(),
        );

        if (
            $gameSession->getCampaign()->getId()
            !== $character->getCampaign()->getId()
        ) {
            return $this->json(
                ['message' => 'Le personnage ne fait pas partie de cette campagne.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (!$character->hasProgression($progression)) {
            return $this->json(
                ['message' => 'Le personnage ne possède pas cette progression.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $sessionState = $stateRepository->findOneBy([
            'gameSession' => $gameSession,
            'character' => $character,
        ]);

        if (
            !$sessionState instanceof CharacterSessionState
            || !$sessionState->isParticipating()
        ) {
            return $this->json(
                ['message' => 'Ce personnage ne participe pas à cette session.'],
                Response::HTTP_NOT_FOUND,
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

        $currentValue = $payload['currentValue'] ?? null;

        if (!is_int($currentValue)) {
            return $this->json(
                ['message' => 'La propriété "currentValue" doit être un entier.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($currentValue < $progression->getMinimumValue()) {
            return $this->json(
                ['message' => 'La valeur est inférieure au minimum de la progression.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (
            $progression->getMaximumValue() !== null
            && $currentValue > $progression->getMaximumValue()
        ) {
            return $this->json(
                ['message' => 'La valeur dépasse le maximum de la progression.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $state = $sessionState->getState();
        $progressions = $state['progressions'] ?? [];
        $found = false;

        foreach ($progressions as &$progressionState) {
            if (
                ($progressionState['id'] ?? null)
                !== $progression->getSlug()
            ) {
                continue;
            }

            $progressionState['currentValue'] = $currentValue;
            $found = true;
            break;
        }

        unset($progressionState);

        if (!$found) {
            $progressions[] = [
                'id' => $progression->getSlug(),
                'currentValue' => $currentValue,
            ];
        }

        $state['progressions'] = $progressions;
        $sessionState->setState(
            $this->stateSynchronizer->synchronizeResources($character, $state),
        );

        $entityManager->flush();

        return $this->json(
            $this->serializeState($sessionState, true),
        );
    }

    #[Route(
        '/public/characters/{accessToken}/level-up/options',
        name: 'api_public_character_level_up_options',
        requirements: ['accessToken' => '[a-f0-9]{64}'],
        methods: ['GET'],
    )]
    public function publicLevelUpOptions(
        string $accessToken,
        PlayerCharacterAccess $playerAccess,
        CharacterLevelUpOptionsService $optionsService,
    ): JsonResponse {
        $state = $playerAccess->requireParticipating(
            $accessToken,
        );

        if (
            $state->getGameSession()->getStatus()
            !== GameSession::STATUS_LIVE
        ) {
            return $this->json(
                ['message' => 'Cette session n’est pas ouverte.'],
                Response::HTTP_CONFLICT,
            );
        }

        if (!$state->isLevelUpAllowed()) {
            return $this->json(
                [
                    'message' =>
                        'La montée de niveau n’a pas été autorisée par le MJ.',
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        return $this->json(
            $optionsService->getOptions(
                $state->getCharacter(),
            ),
        );
    }

    #[Route(
        '/public/characters/{accessToken}/level-up',
        name: 'api_public_character_level_up',
        requirements: ['accessToken' => '[a-f0-9]{64}'],
        methods: ['POST'],
    )]
    public function publicLevelUp(
        string $accessToken,
        Request $request,
        PlayerCharacterAccess $playerAccess,
        CharacterLevelUpRequestResolver $requestResolver,
        CharacterLevelUpService $levelUpService,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $state = $playerAccess->requireParticipating(
            $accessToken,
        );

        if (
            $state->getGameSession()->getStatus()
            !== GameSession::STATUS_LIVE
        ) {
            return $this->json(
                ['message' => 'Cette session n’est pas ouverte.'],
                Response::HTTP_CONFLICT,
            );
        }

        if (!$state->isLevelUpAllowed()) {
            return $this->json(
                [
                    'message' =>
                        'La montée de niveau n’a pas été autorisée par le MJ.',
                ],
                Response::HTTP_FORBIDDEN,
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

            $character = $state->getCharacter();
            $characterClass =
                $selection['characterClass'];

            $level = $levelUpService->levelUp(
                character: $character,
                characterClass: $characterClass,
                subclass: $selection['subclass'],
                advancement: $selection['advancement'],
                hitPointGainMethod:
                    $selection['hitPointGainMethod'],
                hitPointGain:
                    $selection['hitPointGain'],
                authorization: $state,
            );
        } catch (
            \InvalidArgumentException
            | \LogicException
            | \DomainException $exception
        ) {
            return $this->json(
                ['message' => $exception->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->json(
            [
                'message' => sprintf(
                    '%s atteint le niveau %d de %s.',
                    $character->getName(),
                    $character->getLevelInClass(
                        $characterClass,
                    ),
                    $characterClass->getName(),
                ),
                'level' => [
                    'position' =>
                        $level->getPosition(),
                    'classId' =>
                        $level
                            ->getCharacterClass()
                            ->getId(),
                    'className' =>
                        $level
                            ->getCharacterClass()
                            ->getName(),
                    'subclassId' =>
                        $level
                            ->getSubclass()
                            ?->getId(),
                    'subclassName' =>
                        $level
                            ->getSubclass()
                            ?->getName(),
                    'hitPointGain' =>
                        $level->getHitPointGain(),
                    'hitPointGainMethod' =>
                        $level
                            ->getHitPointGainMethod()
                            ?->value,
                ],
                'character' =>
                    $this->profileSerializer
                        ->serialize(
                            $character,
                            $this->extractProgressionValues($state->getState()),
                        ),
                'levelUpAllowed' => false,
                'revision' => $state->getRevision(),
            ],
            Response::HTTP_CREATED,
        );
    }

    /**
     * Retourne au téléphone toutes les données nécessaires au portail.
     */
    #[Route(
        '/public/characters/{accessToken}',
        name: 'api_public_character_session_state_show',
        requirements: ['accessToken' => '[a-f0-9]{64}'],
        methods: ['GET'],
    )]
    public function showPublic(
        string $accessToken,
        PlayerCharacterAccess $playerAccess,
    ): JsonResponse {
        $state = $playerAccess->requireParticipating($accessToken);

        return $this->json($this->serializeState($state));
    }

    /**
     * Enregistre les modifications envoyées par le téléphone.
     */
    #[Route(
        '/public/characters/{accessToken}',
        name: 'api_public_character_session_state_update',
        requirements: ['accessToken' => '[a-f0-9]{64}'],
        methods: ['PATCH'],
    )]
    public function updatePublic(
        string $accessToken,
        Request $request,
        PlayerCharacterAccess $playerAccess,
        EntityManagerInterface $entityManager,
        PlayerCharacterStateUpdater $updater,
    ): JsonResponse {
        $state = $playerAccess->requireParticipating($accessToken);

        if ($state->getGameSession()->getStatus() !== GameSession::STATUS_LIVE) {
            return $this->json(
                ['message' => 'Cette session n’est pas ouverte.'],
                Response::HTTP_CONFLICT,
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

        $newState = $payload['state'] ?? null;

        if (!is_array($newState)) {
            return $this->json(
                ['message' => 'La propriété "state" doit être un objet JSON.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $revision = $payload['revision'] ?? null;
        if (!is_int($revision) || $revision < 1) {
            return $this->json(['message' => 'Une révision entière positive est requise.'], 422);
        }
        if ($state->getRevision() !== $revision) {
            return $this->json(['message' => 'Le personnage a été modifié ailleurs. Rechargez son état.'], 409);
        }
        $mergedState = $updater->merge($state, $newState);
        try {
            return $entityManager->wrapInTransaction(function () use ($entityManager, $state, $revision, $mergedState): JsonResponse {
                $entityManager->lock($state->getGameSession(), LockMode::PESSIMISTIC_READ);
                $entityManager->refresh($state->getGameSession());
                if ($state->getGameSession()->getStatus() !== GameSession::STATUS_LIVE) {
                    return $this->json(['message' => 'Cette session n’est pas ouverte.'], 409);
                }
                $entityManager->lock($state, LockMode::OPTIMISTIC, $revision);
                $state->setState($mergedState);
                $entityManager->flush();
                return $this->json($this->serializeState($state));
            });
        } catch (OptimisticLockException) {
            return $this->json(['message' => 'Le personnage a été modifié ailleurs. Rechargez son état.'], 409);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeState(
        CharacterSessionState $state,
        bool $includeAccessToken = false,
    ): array {
        $character = $state->getCharacter();
        $gameSession = $state->getGameSession();

        $sessionState = $state->getState();

        $sessionState['hitPoints']['effectiveMaximum'] =
            $this->hitPointStateService->effectiveMaximum(
                $character,
                $sessionState,
            );

        $activeEffects = $this->activeEffectRepository->findBy(['targetCharacter' => $character]);

        $data = [
            'id' => $state->getId(),
            'revision' => $state->getRevision(),
            'campaign' => [
                'id' => $gameSession
                    ->getCampaign()
                    ->getId(),
                'configurationKey' => $gameSession
                    ->getCampaign()
                    ->getConfigurationKey(),
            ],
            'session' => [
                'id' => $gameSession->getId(),
                'name' => $gameSession->getName(),
                'status' => $gameSession->getStatus(),
            ],
            'character' => $this->profileSerializer->serialize(
                $character,
                $this->extractProgressionValues($state->getState()),
            ),
            'activeEffects' => array_map(
                static fn ($effect): array => [
                    'id' => $effect->getId(),
                    'type' => $effect->getType(),
                    'amount' => $effect->getAmount(),
                    'sourceCharacter' => $effect->getSourceCharacter() !== null
                        ? [
                            'id' => $effect->getSourceCharacter()->getId(),
                            'name' => $effect->getSourceCharacter()->getName(),
                        ]
                        : null,
                ],
                $activeEffects,
            ),
            'participating' => $state->isParticipating(),
            'levelUpAllowed' => $state->isLevelUpAllowed(),
            'state' => $sessionState,
            'updatedAt' => $state->getUpdatedAt()->format(DATE_ATOM),
        ];

        if ($includeAccessToken) {
            $data['accessToken'] = $state->getAccessToken();
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, int>
     */
    private function extractProgressionValues(array $state): array
    {
        $progressions = $state['progressions'] ?? [];

        if (!is_array($progressions)) {
            return [];
        }

        $values = [];

        foreach ($progressions as $progression) {
            if (
                !is_array($progression)
                || !is_string($progression['id'] ?? null)
                || $progression['id'] === ''
                || !is_int($progression['currentValue'] ?? null)
            ) {
                continue;
            }

            $values[$progression['id']] = $progression['currentValue'];
        }

        return $values;
    }

    #[Route(
        '/public/characters/{accessToken}/action-targets',
        name: 'api_public_character_action_targets',
        requirements: ['accessToken' => '[a-f0-9]{64}'],
        methods: ['GET'],
    )]
    public function actionTargets(
        string $accessToken,
        PlayerCharacterAccess $playerAccess,
        CharacterSessionStateRepository $stateRepository,
    ): JsonResponse {
        $characterState = $playerAccess->requireParticipating(
            $accessToken,
        );

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

    #[Route(
    '/public/characters/{accessToken}/actions/aid',
    name: 'api_public_character_action_aid',
    requirements: [
        'accessToken' => '[a-f0-9]{64}',
    ],
    methods: ['POST'],
)]
public function useAid(
    string $accessToken,
    Request $request,
    PlayerCharacterAccess $playerAccess,
    CharacterSessionStateRepository $stateRepository,
    CharacterAidActionService $aidActionService,
    EntityManagerInterface $entityManager,
): JsonResponse {
    $casterState =
        $playerAccess->requireParticipating(
            $accessToken,
        );

    if (
        $casterState
            ->getGameSession()
            ->getStatus()
        !== GameSession::STATUS_LIVE
    ) {
        return $this->json(
            [
                'message' =>
                    'Cette session n’est pas ouverte.',
            ],
            Response::HTTP_CONFLICT,
        );
    }

    try {
        $payload = $request->toArray();
    } catch (JsonException) {
        return $this->json(
            [
                'message' =>
                    'Le corps JSON est invalide.',
            ],
            Response::HTTP_BAD_REQUEST,
        );
    }

    $spellSlotLevel =
        $payload['spellSlotLevel']
        ?? null;

    $targetIds =
        $payload['targetIds']
        ?? null;

    $revision =
        $payload['revision']
        ?? null;

    if (
        !is_int($spellSlotLevel)
        || $spellSlotLevel < 2
    ) {
        return $this->json(
            [
                'message' =>
                    'Le niveau d’emplacement est invalide.',
            ],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    if (
        !is_array($targetIds)
        || $targetIds === []
        || count($targetIds) > 3
    ) {
        return $this->json(
            [
                'message' =>
                    'Aide doit cibler entre une et trois créatures.',
            ],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    foreach ($targetIds as $targetId) {
        if (!is_int($targetId)) {
            return $this->json(
                [
                    'message' =>
                        'Les identifiants de cibles sont invalides.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
    }

    $targetIds = array_values(
        array_unique($targetIds),
    );

    if (
        count($targetIds) === 0
        || count($targetIds) > 3
    ) {
        return $this->json(
            [
                'message' =>
                    'Aide doit cibler entre une et trois créatures différentes.',
            ],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    if (
        !is_int($revision)
        || $revision < 1
    ) {
        return $this->json(
            [
                'message' =>
                    'Une révision entière positive est requise.',
            ],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    if (
        $casterState->getRevision()
        !== $revision
    ) {
        return $this->json(
            [
                'message' =>
                    'Le personnage a été modifié ailleurs. Rechargez son état.',
            ],
            Response::HTTP_CONFLICT,
        );
    }

    try {
        return $entityManager
            ->wrapInTransaction(
                function () use (
                    $entityManager,
                    $casterState,
                    $stateRepository,
                    $aidActionService,
                    $spellSlotLevel,
                    $targetIds,
                    $revision,
                ): JsonResponse {
                    $entityManager->lock(
                        $casterState
                            ->getGameSession(),
                        \Doctrine\DBAL\LockMode::PESSIMISTIC_READ,
                    );

                    $entityManager->refresh(
                        $casterState
                            ->getGameSession(),
                    );

                    if (
                        $casterState
                            ->getGameSession()
                            ->getStatus()
                        !== GameSession::STATUS_LIVE
                    ) {
                        return $this->json(
                            [
                                'message' =>
                                    'Cette session n’est pas ouverte.',
                            ],
                            Response::HTTP_CONFLICT,
                        );
                    }

                    $entityManager->lock(
                        $casterState,
                        \Doctrine\DBAL\LockMode::OPTIMISTIC,
                        $revision,
                    );

                    $targetStates = [];

                    foreach ($targetIds as $targetId) {
                        $targetState =
                            $stateRepository->findOneBy([
                                'gameSession' =>
                                    $casterState
                                        ->getGameSession(),
                                'character' =>
                                    $targetId,
                                'participating' =>
                                    true,
                            ]);

                        if (
                            !$targetState instanceof
                            CharacterSessionState
                        ) {
                            return $this->json(
                                [
                                    'message' =>
                                        'Une cible ne participe pas à cette session.',
                                ],
                                Response::HTTP_UNPROCESSABLE_ENTITY,
                            );
                        }

                        $entityManager->lock(
                            $targetState,
                            \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE,
                        );

                        $targetStates[] =
                            $targetState;
                    }

                    $aidActionService->apply(
                        $casterState,
                        $spellSlotLevel,
                        $targetStates,
                    );

                    $entityManager->flush();

                    return $this->json(
                        $this->serializeState(
                            $casterState,
                        ),
                    );
                },
            );
    } catch (
        \Doctrine\ORM\OptimisticLockException
    ) {
        return $this->json(
            [
                'message' =>
                    'Le personnage a été modifié ailleurs. Rechargez son état.',
            ],
            Response::HTTP_CONFLICT,
        );
    } catch (\DomainException $exception) {
        return $this->json(
            [
                'message' =>
                    $exception->getMessage(),
            ],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

}

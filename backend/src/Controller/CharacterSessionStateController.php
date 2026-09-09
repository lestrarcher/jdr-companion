<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Character;
use App\Entity\CharacterSessionState;
use App\Entity\GameSession;
use App\Repository\CharacterRepository;
use App\Repository\CharacterSessionStateRepository;
use App\Repository\GameSessionRepository;
use App\Security\Voter\CampaignVoter;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use App\Service\CharacterSessionStateFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\CharacterProfileSerializer;
use App\Service\CharacterLevelUpOptionsService;
use App\Service\CharacterLevelUpRequestResolver;
use App\Service\CharacterLevelUpService;

final class CharacterSessionStateController extends AbstractController
{

    public function __construct(
        private readonly CharacterProfileSerializer $profileSerializer,
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
        '/public/characters/{accessToken}/level-up/options',
        name: 'api_public_character_level_up_options',
        requirements: ['accessToken' => '[a-f0-9]{64}'],
        methods: ['GET'],
    )]
    public function publicLevelUpOptions(
        string $accessToken,
        CharacterSessionStateRepository $stateRepository,
        CharacterLevelUpOptionsService $optionsService,
    ): JsonResponse {
        $state = $stateRepository->findOneByAccessToken(
            $accessToken,
        );

        if (
            !$state instanceof CharacterSessionState
            || !$state->isParticipating()
        ) {
            return $this->json(
                ['message' => 'Lien joueur invalide.'],
                Response::HTTP_NOT_FOUND,
            );
        }

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
        CharacterSessionStateRepository $stateRepository,
        CharacterLevelUpRequestResolver $requestResolver,
        CharacterLevelUpService $levelUpService,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $state = $stateRepository->findOneByAccessToken(
            $accessToken,
        );

        if (
            !$state instanceof CharacterSessionState
            || !$state->isParticipating()
        ) {
            return $this->json(
                ['message' => 'Lien joueur invalide.'],
                Response::HTTP_NOT_FOUND,
            );
        }

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

        $state->setLevelUpAllowed(false);

        $entityManager->flush();

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
                        ->serialize($character),
                'levelUpAllowed' => false,
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
        CharacterSessionStateRepository $stateRepository,
    ): JsonResponse {
        $state = $stateRepository->findOneByAccessToken($accessToken);

        if (!$state instanceof CharacterSessionState || !$state->isParticipating()) {
            return $this->json( ['message' => 'Lien joueur invalide.'], Response::HTTP_NOT_FOUND);
        }

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
        CharacterSessionStateRepository $stateRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $state = $stateRepository->findOneByAccessToken($accessToken);

        if (!$state instanceof CharacterSessionState) {
            return $this->json(
                ['message' => 'Lien joueur invalide.'],
                Response::HTTP_NOT_FOUND,
            );
        }

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

        $state->setState($newState);

        $entityManager->flush();

        return $this->json($this->serializeState($state));
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

        $data = [
            'id' => $state->getId(),
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
            'character' => $this->profileSerializer->serialize($character),
            'participating' => $state->isParticipating(),
            'levelUpAllowed' => $state->isLevelUpAllowed(),
            'state' => $state->getState(),
            'updatedAt' => $state->getUpdatedAt()->format(DATE_ATOM),
        ];

        if ($includeAccessToken) {
            $data['accessToken'] = $state->getAccessToken();
        }

        return $data;
    }
}

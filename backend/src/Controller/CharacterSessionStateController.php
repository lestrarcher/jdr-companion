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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CharacterSessionStateController extends AbstractController
{
    /**
     * Crée ou récupère l’état d’un personnage pour une session.
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

        if ($gameSession->getCampaign()->getId() !== $character->getCampaign()->getId()) {
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
            return $this->json(
                $this->serializeState($existingState, true),
                Response::HTTP_OK,
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

        $initialState = $payload['state'] ?? [];

        if (!is_array($initialState)) {
            return $this->json(
                ['message' => 'La propriété "state" doit être un objet JSON.'],
                Response::HTTP_BAD_REQUEST,
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

        if (!$state instanceof CharacterSessionState) {
            return $this->json(
                ['message' => 'Lien joueur invalide.'],
                Response::HTTP_NOT_FOUND,
            );
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
            'session' => [
                'id' => $gameSession->getId(),
                'name' => $gameSession->getName(),
                'status' => $gameSession->getStatus(),
            ],
            'character' => [
                'id' => $character->getId(),
                'slug' => $character->getSlug(),
                'name' => $character->getName(),
                'playerName' => $character->getPlayerName(),
                'type' => $character->getType(),
                'definition' => $character->getDefinition(),
            ],
            'state' => $state->getState(),
            'updatedAt' => $state->getUpdatedAt()->format(DATE_ATOM),
        ];

        if ($includeAccessToken) {
            $data['accessToken'] = $state->getAccessToken();
        }

        return $data;
    }
}

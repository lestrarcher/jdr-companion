<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GameSession;
use App\Entity\RestRequest;
use App\Entity\User;
use App\Repository\CharacterSessionStateRepository;
use App\Repository\GameSessionRepository;
use App\Repository\RestRequestRepository;
use App\Security\Voter\CampaignVoter;
use App\Service\CharacterRestService;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RestRequestController extends AbstractController
{
    #[Route(
        '/public/characters/{accessToken}/rest-requests',
        name: 'api_public_rest_request_create',
        requirements: [
            'accessToken' => '[a-f0-9]{64}',
        ],
        methods: ['POST'],
    )]
    public function create(
        string $accessToken,
        Request $request,
        CharacterSessionStateRepository $stateRepository,
        RestRequestRepository $restRequestRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $characterState =
            $stateRepository
                ->findOneByAccessToken(
                    $accessToken,
                );

        if ($characterState === null) {
            return $this->json(
                ['message' => 'Lien joueur invalide.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        if (
            $characterState
                ->getGameSession()
                ->getStatus() !==
            GameSession::STATUS_LIVE
        ) {
            return $this->json(
                [
                    'message' =>
                        'Cette session n’est pas ouverte.',
                ],
                Response::HTTP_CONFLICT,
            );
        }

        $pendingRequest =
            $restRequestRepository
                ->findPendingForCharacterState(
                    $characterState,
                );

        if ($pendingRequest !== null) {
            return $this->json(
                [
                    'message' =>
                        'Une demande de repos est déjà en attente.',

                    'restRequest' =>
                        $this->serializeRestRequest(
                            $pendingRequest,
                        ),
                ],
                Response::HTTP_CONFLICT,
            );
        }

        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            return $this->json(
                ['message' => 'Le JSON est invalide.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $type = $payload['type'] ?? null;

        if (
            !is_string($type) ||
            !in_array(
                $type,
                RestRequest::allowedTypes(),
                true,
            )
        ) {
            return $this->json(
                [
                    'message' =>
                        'Le type de repos est invalide.',
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $restRequest = new RestRequest(
            $characterState,
            $type,
        );

        $entityManager->persist(
            $restRequest,
        );

        $entityManager->flush();

        return $this->json(
            [
                'restRequest' =>
                    $this->serializeRestRequest(
                        $restRequest,
                    ),
            ],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/public/characters/{accessToken}/rest-requests/latest',
        name: 'api_public_rest_request_latest',
        requirements: [
            'accessToken' => '[a-f0-9]{64}',
        ],
        methods: ['GET'],
    )]
    public function latest(
        string $accessToken,
        CharacterSessionStateRepository $stateRepository,
        RestRequestRepository $restRequestRepository,
    ): JsonResponse {
        $characterState =
            $stateRepository
                ->findOneByAccessToken(
                    $accessToken,
                );

        if ($characterState === null) {
            return $this->json(
                ['message' => 'Lien joueur invalide.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $restRequest =
            $restRequestRepository
                ->findLatestForCharacterState(
                    $characterState,
                );

        return $this->json([
            'restRequest' =>
                $restRequest === null
                    ? null
                    : $this->serializeRestRequest(
                        $restRequest,
                    ),
        ]);
    }

    #[Route(
        '/sessions/{sessionId}/rest-requests',
        name: 'api_rest_request_list',
        requirements: [
            'sessionId' => '\d+',
        ],
        methods: ['GET'],
    )]
    public function list(
        int $sessionId,
        GameSessionRepository $gameSessionRepository,
        RestRequestRepository $restRequestRepository,
    ): JsonResponse {
        $gameSession =
            $gameSessionRepository->find(
                $sessionId,
            );

        if ($gameSession === null) {
            return $this->json(
                ['message' => 'Session introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $gameSession->getCampaign(),
        );

        $requests =
            $restRequestRepository
                ->findPendingForSession(
                    $gameSession,
                );

        return $this->json([
            'restRequests' => array_map(
                fn (
                    RestRequest $restRequest,
                ): array =>
                    $this->serializeRestRequest(
                        $restRequest,
                    ),
                $requests,
            ),
        ]);
    }

       #[Route(
        '/rest-requests/{requestId}',
        name: 'api_rest_request_resolve',
        requirements: [
            'requestId' => '\d+',
        ],
        methods: ['PATCH'],
    )]
    public function resolve(
        int $requestId,
        Request $request,
        RestRequestRepository $restRequestRepository,
        CharacterRestService $characterRestService,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $restRequest =
            $restRequestRepository->find(
                $requestId,
            );

        if ($restRequest === null) {
            return $this->json(
                [
                    'message' =>
                        'Demande de repos introuvable.',
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        $campaign = $restRequest
            ->getCharacterSessionState()
            ->getGameSession()
            ->getCampaign();

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $campaign,
        );

        if (!$restRequest->isPending()) {
            return $this->json(
                [
                    'message' =>
                        'Cette demande a déjà été traitée.',
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
                        'Le JSON est invalide.',
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $status = $payload['status'] ?? null;

        if (
            !is_string($status) ||
            !in_array(
                $status,
                [
                    RestRequest::STATUS_APPROVED,
                    RestRequest::STATUS_REJECTED,
                ],
                true,
            )
        ) {
            return $this->json(
                [
                    'message' =>
                        'La réponse doit être "approved" ou "rejected".',
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(
                [
                    'message' =>
                        'Utilisateur non authentifié.',
                ],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        if (
            $status ===
            RestRequest::STATUS_APPROVED
        ) {
            /*
             * Le backend applique lui-même le repos.
             * Le téléphone récupérera ensuite le
             * nouvel état persisté.
             */
            $characterRestService->apply(
                $restRequest
                    ->getCharacterSessionState(),
                $restRequest->getType(),
            );

            $restRequest->approve($user);
        } else {
            $restRequest->reject($user);
        }

        $entityManager->flush();

        return $this->json([
            'restRequest' =>
                $this->serializeRestRequest(
                    $restRequest,
                ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRestRequest(
        RestRequest $restRequest,
    ): array {
        $characterState =
            $restRequest
                ->getCharacterSessionState();

        $character =
            $characterState->getCharacter();

        return [
            'id' => $restRequest->getId(),
            'type' => $restRequest->getType(),
            'status' => $restRequest->getStatus(),

            'requestedAt' =>
                $restRequest
                    ->getRequestedAt()
                    ->format(DATE_ATOM),

            'resolvedAt' =>
                $restRequest
                    ->getResolvedAt()
                    ?->format(DATE_ATOM),

            'character' => [
                'id' => $character->getId(),
                'slug' => $character->getSlug(),
                'name' => $character->getName(),
                'playerName' =>
                    $character->getPlayerName(),
            ],
        ];
    }
}

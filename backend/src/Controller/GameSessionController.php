<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\GameSession;
use App\Security\Voter\CampaignVoter;
use App\Repository\CampaignRepository;
use App\Repository\GameSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class GameSessionController extends AbstractController
{
    #[Route(
        '/campaigns/{campaignId}/sessions',
        name: 'api_session_list',
        requirements: ['campaignId' => '\d+'],
        methods: ['GET'],
    )]
    public function list(
        int $campaignId,
        CampaignRepository $campaignRepository,
        GameSessionRepository $sessionRepository,
    ): JsonResponse {
        $campaign = $this->getOwnedCampaign(
            $campaignId,
            $campaignRepository,
        );

        $sessions = $sessionRepository->findBy(
            ['campaign' => $campaign],
            ['createdAt' => 'DESC'],
        );

        return $this->json([
            'sessions' => array_map(
                fn (GameSession $session): array =>
                    $this->serializeSession($session),
                $sessions,
            ),
        ]);
    }

    #[Route(
        '/campaigns/{campaignId}/sessions',
        name: 'api_session_create',
        requirements: ['campaignId' => '\d+'],
        methods: ['POST'],
    )]
    public function create(
        int $campaignId,
        Request $request,
        CampaignRepository $campaignRepository,
        GameSessionRepository $sessionRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $campaign = $this->getOwnedCampaign(
            $campaignId,
            $campaignRepository,
        );

        $payload = $request->toArray();

        $slug = trim(
            (string) ($payload['slug'] ?? ''),
        );

        $name = trim(
            (string) ($payload['name'] ?? ''),
        );

        if (
            $slug === '' ||
            !preg_match('/^[a-z0-9-]+$/', $slug)
        ) {
            return $this->json(
                [
                    'message' =>
                        'Le slug de session est invalide.',
                ],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($name === '') {
            return $this->json(
                [
                    'message' =>
                        'Le nom de la session est obligatoire.',
                ],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $existingSession = $sessionRepository->findOneBy([
            'campaign' => $campaign,
            'slug' => $slug,
        ]);

        if ($existingSession) {
            return $this->json(
                [
                    'message' =>
                        'Ce slug de session existe déjà.',
                ],
                JsonResponse::HTTP_CONFLICT,
            );
        }

        $session = new GameSession(
            $campaign,
            $slug,
            $name,
        );

        $displayState = $payload['displayState'] ?? [];

        if (is_array($displayState)) {
            $session->setDisplayState($displayState);
        }

        $entityManager->persist($session);
        $entityManager->flush();

        return $this->json(
            [
                'session' =>
                    $this->serializeSession($session),
            ],
            JsonResponse::HTTP_CREATED,
        );
    }

    #[Route(
        '/sessions/{sessionId}',
        name: 'api_session_update',
        requirements: ['sessionId' => '\d+'],
        methods: ['PATCH'],
    )]
    public function update(
        int $sessionId,
        Request $request,
        GameSessionRepository $sessionRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $session = $sessionRepository->find($sessionId);

        if (!$session) {
            throw $this->createNotFoundException(
                'Session inconnue.',
            );
        }

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $session->getCampaign(),
        );

        $payload = $request->toArray();

        if (array_key_exists('status', $payload)) {
            try {
                $session->setStatus(
                    (string) $payload['status'],
                );
            } catch (\InvalidArgumentException $exception) {
                return $this->json(
                    [
                        'message' => $exception->getMessage(),
                    ],
                    JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        if (
            array_key_exists('displayState', $payload) &&
            is_array($payload['displayState'])
        ) {
            $session->setDisplayState(
                $payload['displayState'],
            );
        }

        $entityManager->flush();

        return $this->json([
            'session' =>
                $this->serializeSession($session),
        ]);
    }

    #[Route(
        '/public/sessions/{accessToken}',
        name: 'api_public_session_show',
        requirements: [
            'accessToken' => '[a-f0-9]{64}',
        ],
        methods: ['GET'],
    )]
    public function publicShow(
        string $accessToken,
        GameSessionRepository $sessionRepository,
    ): JsonResponse {
        $session = $sessionRepository->findOneBy([
            'displayAccessToken' => $accessToken,
        ]);

        if (!$session) {
            throw $this->createNotFoundException(
                'Lien de session invalide.',
            );
        }

        return $this->json([
            'session' => [
                'id' => $session->getId(),
                'campaignId' =>
                    $session->getCampaign()->getId(),
                'status' => $session->getStatus(),
                'displayState' =>
                    $session->getDisplayState(),
                'updatedAt' =>
                    $session->getUpdatedAt()
                        ->format(DATE_ATOM),
            ],
        ]);
    }

    private function getOwnedCampaign(
        int $campaignId,
        CampaignRepository $campaignRepository,
    ): Campaign {
        $campaign = $campaignRepository->find($campaignId);

        if (!$campaign) {
            throw $this->createNotFoundException(
                'Campagne inconnue.',
            );
        }

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $campaign,
        );

        return $campaign;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSession(
        GameSession $session,
    ): array {
        return [
            'id' => $session->getId(),
            'campaignId' =>
                $session->getCampaign()->getId(),
            'slug' => $session->getSlug(),
            'name' => $session->getName(),
            'status' => $session->getStatus(),
            'displayState' =>
                $session->getDisplayState(),
            'displayAccessToken' =>
                $session->getDisplayAccessToken(),
            'createdAt' =>
                $session->getCreatedAt()
                    ->format(DATE_ATOM),
            'updatedAt' =>
                $session->getUpdatedAt()
                    ->format(DATE_ATOM),
        ];
    }

    #[Route(
    '/sessions/{sessionId}',
    name: 'api_session_show',
    requirements: ['sessionId' => '\d+'],
    methods: ['GET'],
)]
public function show(
    int $sessionId,
    GameSessionRepository $sessionRepository,
): JsonResponse {
    $session = $sessionRepository->find($sessionId);

    if (!$session) {
        throw $this->createNotFoundException(
            'Session inconnue.',
        );
    }

    $this->denyAccessUnlessGranted(
        CampaignVoter::VIEW,
        $session->getCampaign(),
    );

    return $this->json([
        'session' =>
            $this->serializeSession($session),
    ]);
}
}

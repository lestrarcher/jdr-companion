<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\User;
use App\Security\Voter\CampaignVoter;
use App\Repository\CampaignRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/campaigns')]
final class CampaignController extends AbstractController
{
    #[Route(
        '',
        name: 'api_campaign_list',
        methods: ['GET'],
    )]
    public function list(
        CampaignRepository $campaignRepository,
    ): JsonResponse {
        $user = $this->getAuthenticatedUser();

        $campaigns = $campaignRepository->findBy(
            ['owner' => $user],
            ['name' => 'ASC'],
        );

        return $this->json([
            'campaigns' => array_map(
                static fn (Campaign $campaign): array => [
                    'id' => $campaign->getId(),
                    'slug' => $campaign->getSlug(),
                    'name' => $campaign->getName(),
                ],
                $campaigns,
            ),
        ]);
    }

    #[Route(
        '',
        name: 'api_campaign_create',
        methods: ['POST'],
    )]
    public function create(
        Request $request,
        CampaignRepository $campaignRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $user = $this->getAuthenticatedUser();
        $payload = $request->toArray();

        $slug = trim(
            (string) ($payload['slug'] ?? ''),
        );

        $name = trim(
            (string) ($payload['name'] ?? ''),
        );

        if (
            $slug === '' ||
            !preg_match(
                '/^[a-z0-9-]+$/',
                $slug,
            )
        ) {
            return $this->json(
                [
                    'message' => implode(' ', [
                        'Le slug doit contenir uniquement',
                        'des lettres minuscules, des chiffres',
                        'et des tirets.',
                    ]),
                ],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($name === '') {
            return $this->json(
                [
                    'message' =>
                        'Le nom de la campagne est obligatoire.',
                ],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $existingCampaign =
            $campaignRepository->findOneBy([
                'owner' => $user,
                'slug' => $slug,
            ]);

        if ($existingCampaign) {
            return $this->json(
                [
                    'message' =>
                        'Vous utilisez déjà ce slug.',
                ],
                JsonResponse::HTTP_CONFLICT,
            );
        }

        $campaign = new Campaign(
            $user,
            $slug,
            $name,
        );

        $entityManager->persist($campaign);
        $entityManager->flush();

        return $this->json(
            [
                'campaign' => [
                    'id' => $campaign->getId(),
                    'slug' => $campaign->getSlug(),
                    'name' => $campaign->getName(),
                ],
            ],
            JsonResponse::HTTP_CREATED,
        );
    }

    private function getAuthenticatedUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException(
                'Authentification requise.',
            );
        }

        return $user;
    }
}

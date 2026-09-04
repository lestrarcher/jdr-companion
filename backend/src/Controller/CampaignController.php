<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\User;
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
                fn (Campaign $campaign): array =>
                    $this->serializeCampaign($campaign),
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

        /*
         * Compatibilité avec les anciennes requêtes :
         * si aucune configuration n'est fournie,
         * on utilise celle de Strahd.
         */
        $configurationKey = trim(
            (string) (
                $payload['configurationKey']
                ?? Campaign::CONFIGURATION_STRAHD
            ),
        );

        if (
            $slug === ''
            || !preg_match('/^[a-z0-9-]+$/', $slug)
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

        if (
            !in_array(
                $configurationKey,
                Campaign::allowedConfigurationKeys(),
                true,
            )
        ) {
            return $this->json(
                [
                    'message' =>
                        'La configuration de campagne est invalide.',
                    'allowedConfigurationKeys' =>
                        Campaign::allowedConfigurationKeys(),
                ],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $existingCampaign = $campaignRepository->findOneBy([
            'owner' => $user,
            'slug' => $slug,
        ]);

        if ($existingCampaign instanceof Campaign) {
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

        $campaign->setConfigurationKey($configurationKey);

        $entityManager->persist($campaign);
        $entityManager->flush();

        return $this->json(
            [
                'campaign' =>
                    $this->serializeCampaign($campaign),
            ],
            JsonResponse::HTTP_CREATED,
        );
    }

    /**
     * @return array{
     *     id: int|null,
     *     slug: string,
     *     name: string,
     *     configurationKey: string
     * }
     */
    private function serializeCampaign(
        Campaign $campaign,
    ): array {
        return [
            'id' => $campaign->getId(),
            'slug' => $campaign->getSlug(),
            'name' => $campaign->getName(),
            'configurationKey' =>
                $campaign->getConfigurationKey(),
        ];
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

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\Weather;
use App\Repository\CampaignRepository;
use App\Repository\WeatherRepository;
use App\Security\Voter\CampaignVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class WeatherController extends AbstractController
{
    #[Route(
        '/campaigns/{campaignId}/weathers',
        name: 'api_weather_list',
        requirements: ['campaignId' => '\d+'],
        methods: ['GET'],
    )]
    public function list(
        int $campaignId,
        CampaignRepository $campaignRepository,
        WeatherRepository $weatherRepository,
    ): JsonResponse {
        $campaign = $this->getOwnedCampaign(
            $campaignId,
            $campaignRepository,
        );

        $weathers =
            $weatherRepository->findAvailableForCampaign(
                $campaign,
            );

        return $this->json([
            'weathers' => array_map(
                fn (Weather $weather): array =>
                    $this->serializeWeather($weather),
                $weathers,
            ),
        ]);
    }

    private function getOwnedCampaign(
        int $campaignId,
        CampaignRepository $campaignRepository,
    ): Campaign {
        $campaign =
            $campaignRepository->find($campaignId);

        if (!$campaign instanceof Campaign) {
            throw $this->createNotFoundException(
                'Campagne introuvable.',
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
    private function serializeWeather(
        Weather $weather,
    ): array {
        return [
            'id' => $weather->getId(),
            'key' => $weather->getKey(),
            'label' => $weather->getLabel(),
            'imageUrl' => $weather->getImageUrl(),
            'alt' => $weather->getAlt(),
            'system' => $weather->isSystem(),
            'campaignId' =>
                $weather->getCampaign()?->getId(),
        ];
    }
}

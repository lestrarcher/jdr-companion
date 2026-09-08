<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\Character;
use App\Repository\CharacterRepository;
use App\Security\Voter\CampaignVoter;
use App\Service\CharacterProfileSerializer;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/campaigns/{campaignId}/characters/{characterId}',
    requirements: [
        'campaignId' => '\d+',
        'characterId' => '\d+',
    ],
)]
final class CharacterProfileController extends AbstractController
{
    #[Route('', name: 'api_character_profile', methods: ['GET'])]
    public function show(
        #[MapEntity(id: 'campaignId')]
        Campaign $campaign,
        int $characterId,
        CharacterRepository $characterRepository,
        CharacterProfileSerializer $serializer,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(CampaignVoter::VIEW, $campaign);

        $character = $characterRepository->findOneBy([
            'id' => $characterId,
            'campaign' => $campaign,
        ]);

        if (!$character instanceof Character) {
            return $this->json(
                ['message' => 'Personnage introuvable dans cette campagne.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return $this->json([
            'character' => $serializer->serialize($character),
        ]);
    }
}

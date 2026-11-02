<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\{PactRitualCopy, PlayerCharacterAccess};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

final class PactRitualCopyController extends AbstractController
{
    #[Route('/public/characters/{accessToken}/pact-ritual-copy/quote', requirements: ['accessToken' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function quote(string $accessToken, Request $request, PlayerCharacterAccess $access, PactRitualCopy $service): JsonResponse
    {
        $character = $access->requireParticipating($accessToken)->getCharacter();
        return $this->json($service->quote($character, $request->query->getInt('sourceOwnedItemId'),
            $request->query->getInt('destinationOwnedItemId'), $request->query->getInt('spellId')));
    }

    #[Route('/public/characters/{accessToken}/pact-ritual-copy', requirements: ['accessToken' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function copy(string $accessToken, Request $request, PlayerCharacterAccess $access, PactRitualCopy $service): JsonResponse
    {
        $character = $access->requireParticipating($accessToken)->getCharacter();
        return $this->json($service->execute($character, $request->toArray()));
    }
}

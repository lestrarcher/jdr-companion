<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\{Character, User};
use App\Service\{PhysicalSpellCopy, PlayerCharacterAccess};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PhysicalSpellCopyController extends AbstractController
{
    #[Route('/characters/{id}/physical-spell-copy/quote', methods: ['GET'])]
    public function quote(Character $character, Request $request, PhysicalSpellCopy $service): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) throw $this->createNotFoundException();
        return $this->json($service->quote($character, $actor,
            $request->query->getInt('policyId'), $request->query->getInt('sourceOwnedItemId'),
            $request->query->getInt('destinationOwnedItemId'), $request->query->getInt('spellId')));
    }

    #[Route('/public/characters/{accessToken}/physical-spell-copy/quote', requirements: ['accessToken' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function playerQuote(string $accessToken, Request $request, PlayerCharacterAccess $access, PhysicalSpellCopy $service): JsonResponse
    {
        $character = $access->requireParticipating($accessToken)->getCharacter();
        return $this->json($service->quote($character, $character->getCampaign()->getOwner(),
            $request->query->getInt('policyId'), $request->query->getInt('sourceOwnedItemId'),
            $request->query->getInt('destinationOwnedItemId'), $request->query->getInt('spellId')));
    }

    #[Route('/characters/{id}/physical-spell-copy', methods: ['POST'])]
    public function copy(Character $character, Request $request, PhysicalSpellCopy $service): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) throw $this->createNotFoundException();
        return $this->json($service->execute($character, $actor, $request->toArray()));
    }

    #[Route('/public/characters/{accessToken}/physical-spell-copy', requirements: ['accessToken' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function playerCopy(string $accessToken, Request $request, PlayerCharacterAccess $access, PhysicalSpellCopy $service): JsonResponse
    {
        $state = $access->requireParticipating($accessToken);
        $character = $state->getCharacter();
        return $this->json($service->execute($character, $character->getCampaign()->getOwner(), $request->toArray()));
    }
}

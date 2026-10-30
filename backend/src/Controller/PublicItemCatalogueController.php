<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\{ItemCatalogue, PlayerCharacterAccess};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/characters/{accessToken}/item-catalogue', requirements: ['accessToken'=>'[a-f0-9]{64}'])]
final class PublicItemCatalogueController extends AbstractController
{
    public function __construct(private PlayerCharacterAccess $access, private ItemCatalogue $catalogue) {}

    #[Route('', name: 'api_public_item_catalogue_list', methods: ['GET'])]
    public function list(string $accessToken, Request $request): JsonResponse
    {
        $campaign = $this->access->requireParticipating($accessToken)->getCharacter()->getCampaign();
        return $this->json($this->catalogue->list($campaign, true, $request->query->getString('q'),
            $request->query->has('category') ? $request->query->getString('category') : null, $request->query->getInt('page', 1)));
    }

    #[Route('/{id}', name: 'api_public_item_catalogue_show', requirements: ['id'=>'\d+'], methods: ['GET'])]
    public function show(string $accessToken, int $id): JsonResponse
    {
        $campaign = $this->access->requireParticipating($accessToken)->getCharacter()->getCampaign();
        return $this->json(['item'=>$this->catalogue->present($campaign, $this->catalogue->requireDefinition($campaign, $id, true), true)]);
    }
}

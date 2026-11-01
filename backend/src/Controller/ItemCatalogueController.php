<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Repository\CampaignRepository;
use App\Security\Voter\CampaignVoter;
use App\Service\ItemCatalogue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/campaigns/{campaignId}/item-catalogue', requirements: ['campaignId'=>'\d+'])]
final class ItemCatalogueController extends AbstractController
{
    public function __construct(private CampaignRepository $campaigns, private ItemCatalogue $catalogue) {}

    #[Route('', name: 'api_item_catalogue_list', methods: ['GET'])]
    public function list(int $campaignId, Request $request): JsonResponse
    {
        return $this->json($this->catalogue->list($this->campaign($campaignId), false,
            $request->query->getString('q'), $request->query->has('category') ? $request->query->getString('category') : null, $request->query->getInt('page', 1)));
    }

    #[Route('/rarities', name: 'api_item_catalogue_rarities_get', methods: ['GET'])]
    public function rarities(int $campaignId): JsonResponse
    {
        return $this->json($this->catalogue->allowedRarities($this->campaign($campaignId)));
    }

    #[Route('/rarities', name: 'api_item_catalogue_rarities_put', methods: ['PUT'])]
    public function updateRarities(int $campaignId, Request $request): JsonResponse
    {
        $payload = $request->toArray();
        if (array_keys($payload) !== ['rarities'] || !is_array($payload['rarities'])) {
            throw new UnprocessableEntityHttpException('Liste des raretés requise.');
        }
        return $this->json($this->catalogue->setAllowedRarities($this->campaign($campaignId), $payload['rarities']));
    }

    #[Route('/{id}', name: 'api_item_catalogue_show', requirements: ['id'=>'\d+'], methods: ['GET'])]
    public function show(int $campaignId, int $id): JsonResponse
    {
        $campaign = $this->campaign($campaignId);
        return $this->json(['item'=>$this->catalogue->present($campaign, $this->catalogue->requireDefinition($campaign, $id, false), false)]);
    }

    #[Route('', name: 'api_item_catalogue_create', methods: ['POST'])]
    public function create(int $campaignId, Request $request): JsonResponse
    {
        $campaign = $this->campaign($campaignId);
        return $this->json(['item'=>$this->catalogue->present($campaign, $this->catalogue->create($campaign, $request->toArray()), false)], 201);
    }

    #[Route('/{id}', name: 'api_item_catalogue_describe', requirements: ['id'=>'\d+'], methods: ['PATCH'])]
    public function describe(int $campaignId, int $id, Request $request): JsonResponse
    {
        $campaign = $this->campaign($campaignId);
        return $this->json(['item'=>$this->catalogue->present($campaign, $this->catalogue->describe($campaign, $id, $request->toArray()), false)]);
    }

    #[Route('/{id}/visibility', name: 'api_item_catalogue_visibility', requirements: ['id'=>'\d+'], methods: ['PUT'])]
    public function visibility(int $campaignId, int $id, Request $request): JsonResponse
    {
        $campaign = $this->campaign($campaignId);
        $payload = $request->toArray();
        if (array_keys($payload) !== ['decision'] || !is_string($payload['decision'])) {
            throw new UnprocessableEntityHttpException('Une décision de visibilité est requise.');
        }
        return $this->json(['item'=>$this->catalogue->visibility($campaign, $id, $payload['decision'])]);
    }

    private function campaign(int $id): Campaign
    {
        $campaign = $this->campaigns->find($id) ?? throw $this->createNotFoundException('Campagne introuvable.');
        $this->denyAccessUnlessGranted(CampaignVoter::MANAGE, $campaign);
        return $campaign;
    }
}

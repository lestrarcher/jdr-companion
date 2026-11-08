<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MagicItem;
use App\Enum\ReferenceOrigin;
use App\Repository\CampaignRepository;
use App\Security\Voter\CampaignVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

/** Configuration API only: never invoked by imports or migrations. */
final class MagicItemArmorClassController extends AbstractController
{
    #[Route('/campaigns/{campaignId}/magic-items/{itemId}/armor-class-effects', name: 'api_campaign_item_ac_effects', requirements: ['campaignId' => '\d+', 'itemId' => '\d+'], methods: ['PUT'])]
    public function campaign(int $campaignId, int $itemId, Request $request, CampaignRepository $campaigns, EntityManagerInterface $em): JsonResponse
    {
        $campaign = $campaigns->find($campaignId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(CampaignVoter::MANAGE, $campaign);
        $item = $em->find(MagicItem::class, $itemId);
        if (!$item || $item->getCampaign()?->getId() !== $campaign->getId()) throw $this->createNotFoundException();
        return $this->configure($item, $request, $em);
    }

    #[Route('/dnd/items/{itemId}/armor-class-effects', name: 'api_reference_item_ac_effects', requirements: ['itemId' => '\d+'], methods: ['PUT'])]
    public function reference(int $itemId, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_REFERENCE_ADMIN');
        $item = $em->find(MagicItem::class, $itemId);
        if (!$item || $item->getOrigin() !== ReferenceOrigin::Official || $item->getOwner() !== null || $item->getCampaign() !== null) {
            throw $this->createNotFoundException();
        }
        return $this->configure($item, $request, $em);
    }

    private function configure(MagicItem $item, Request $request, EntityManagerInterface $em): JsonResponse
    {
        try { $payload = $request->toArray(); } catch (\Symfony\Component\HttpFoundation\Exception\JsonException) {
            return $this->json(['message' => 'Objet JSON requis.'], 400);
        }
        if (array_keys($payload) !== ['armorClassEffects'] || ($payload['armorClassEffects'] !== null && !is_array($payload['armorClassEffects']))) {
            return $this->json(['message' => 'Seul armorClassEffects (liste ou null) est accepté.'], 422);
        }
        try { $item->configureArmorClassEffects($payload['armorClassEffects']); } catch (\InvalidArgumentException $error) {
            return $this->json(['message' => $error->getMessage()], 422);
        }
        $em->flush();
        return $this->json(['id' => $item->getId(), 'armorClassEffects' => $item->getArmorClassEffects()]);
    }
}

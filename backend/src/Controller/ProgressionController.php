<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ProgressionDefinition;
use App\Entity\ProgressionStage;
use App\Entity\ProgressionAdjustmentRule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ProgressionController extends AbstractController
{
    #[Route('/campaigns/{campaignId}/dnd/progressions', name: 'api_campaign_dnd_progressions', requirements: ['campaignId' => '\d+'], methods: ['GET'])]
    public function campaignCatalogue(#[\Symfony\Bridge\Doctrine\Attribute\MapEntity(id: 'campaignId')] \App\Entity\Campaign $campaign, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGranted(\App\Security\Voter\CampaignVoter::VIEW, $campaign);
        return $this->json(['progressions' => array_map($this->serializeProgression(...),
            \App\Service\ReferenceVisibility::choices($entityManager, ProgressionDefinition::class, $campaign->getOwner()))]);
    }

    private function serializeProgression(
        ProgressionDefinition $progression,
    ): array {
        return [
            'id' => $progression->getId(),
            'slug' => $progression->getSlug(),
            'name' => $progression->getName(),
            'description' => $progression->getDescription(),
            'minimumValue' => $progression->getMinimumValue(),
            'maximumValue' => $progression->getMaximumValue(),
            'accentColor' => $progression->getAccentColor(),
            'gainLabel' => $progression->getGainLabel(),
            'spendLabel' => $progression->getSpendLabel(),
            'bulkAdjustmentEnabled' => $progression->isBulkAdjustmentEnabled(),
            'custom' => $progression->isCustom(),
            'adjustmentRules' => $this->serializeAdjustmentRules($progression),
            'stages' => array_map(
                $this->serializeStage(...),
                $progression->getStages()->toArray(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeStage(
        ProgressionStage $stage,
    ): array {
        return [
            'id' => $stage->getId(),
            'label' => $stage->getLabel(),
            'description' => $stage->getDescription(),
            'minimumValue' => $stage->getMinimumValue(),
            'maximumValue' => $stage->getMaximumValue(),
            'iconUrl' => $stage->getIconUrl(),
            'displayOrder' => $stage->getDisplayOrder(),
        ];
    }

    /** GM/reference data only: deliberately not used by CharacterProfileSerializer. */
    private function serializeAdjustmentRules(ProgressionDefinition $progression): array
    {
        $rules = $progression->getAdjustmentRules()->toArray();
        usort($rules, static fn (ProgressionAdjustmentRule $a, ProgressionAdjustmentRule $b): int =>
            [$a->getDisplayOrder(), $a->getId()] <=> [$b->getDisplayOrder(), $b->getId()]);
        return array_map(static fn (ProgressionAdjustmentRule $rule): array => [
            'id' => $rule->getId(),
            'direction' => $rule->getDirection()->value,
            'triggerType' => $rule->getTriggerType(),
            'description' => $rule->getDescription(),
            'adjustmentLabel' => $rule->getAdjustmentLabel(),
            'displayOrder' => $rule->getDisplayOrder(),
        ], $rules);
    }
}

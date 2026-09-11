<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\Character;
use App\Entity\CharacterProgression;
use App\Entity\ProgressionDefinition;
use App\Security\Voter\CampaignVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\CharacterSessionStateSynchronizer;

#[Route(
    '/campaigns/{campaignId}/characters/{characterId}/progressions',
    requirements: [
        'campaignId' => '\d+',
        'characterId' => '\d+',
    ],
)]
final class CharacterProgressionController extends AbstractController
{
    #[Route(
        '',
        name: 'api_character_progression_list',
        methods: ['GET'],
    )]
    public function list(
        #[MapEntity(id: 'campaignId')]
        Campaign $campaign,
        #[MapEntity(
            mapping: [
                'characterId' => 'id',
                'campaignId' => 'campaign',
            ],
        )]
        Character $character,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(
            CampaignVoter::VIEW,
            $campaign,
        );

        return $this->json([
            'progressions' => array_map(
                fn (CharacterProgression $progression): array =>
                    $this->serializeProgression($progression),
                $character->getProgressions()->toArray(),
            ),
        ]);
    }

    #[Route(
        '/{progressionId}',
        name: 'api_character_progression_add',
        requirements: ['progressionId' => '\d+'],
        methods: ['POST'],
    )]
    public function add(
        #[MapEntity(id: 'campaignId')]
        Campaign $campaign,
        #[MapEntity(
            mapping: [
                'characterId' => 'id',
                'campaignId' => 'campaign',
            ],
        )]
        Character $character,
        #[MapEntity(id: 'progressionId')]
        ProgressionDefinition $progressionDefinition,
        EntityManagerInterface $entityManager,
        CharacterSessionStateSynchronizer $sessionStateSynchronizer,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $campaign,
        );

        if ($character->hasProgression($progressionDefinition)) {
            return $this->json(
                [
                    'message' =>
                        'Le personnage possède déjà cette progression.',
                ],
                JsonResponse::HTTP_CONFLICT,
            );
        }

        $progression = new CharacterProgression(
            $character,
            $progressionDefinition,
        );

        $character->addProgression($progression);

        $entityManager->persist($progression);

        $sessionStateSynchronizer
            ->synchronizeProgressionAssignment($character, $progressionDefinition);

        $entityManager->flush();

        return $this->json(
            [
                'progression' =>
                    $this->serializeProgression($progression),
            ],
            JsonResponse::HTTP_CREATED,
        );
    }

    #[Route(
        '/{progressionId}',
        name: 'api_character_progression_remove',
        requirements: ['progressionId' => '\d+'],
        methods: ['DELETE'],
    )]
    public function remove(
        #[MapEntity(id: 'campaignId')]
        Campaign $campaign,
        #[MapEntity(
            mapping: [
                'characterId' => 'id',
                'campaignId' => 'campaign',
            ],
        )]
        Character $character,
        #[MapEntity(id: 'progressionId')]
        ProgressionDefinition $progressionDefinition,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $campaign,
        );

        $characterProgression = null;

        foreach ($character->getProgressions() as $progression) {
            if (
                $progression->getProgressionDefinition()
                === $progressionDefinition
            ) {
                $characterProgression = $progression;
                break;
            }
        }

        if ($characterProgression === null) {
            return $this->json(
                [
                    'message' =>
                        'Le personnage ne possède pas cette progression.',
                ],
                JsonResponse::HTTP_NOT_FOUND,
            );
        }

        $character->removeProgression(
            $characterProgression,
        );

        $entityManager->remove(
            $characterProgression,
        );

        $entityManager->flush();

        return $this->json([
            'message' => 'Progression retirée du personnage.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProgression(
        CharacterProgression $progression,
    ): array {
        $definition =
            $progression->getProgressionDefinition();

        return [
            'id' => $progression->getId(),
            'definitionId' => $definition->getId(),
            'slug' => $definition->getSlug(),
            'name' => $definition->getName(),
            'minimumValue' =>
                $definition->getMinimumValue(),
            'maximumValue' =>
                $definition->getMaximumValue(),
        ];
    }
}

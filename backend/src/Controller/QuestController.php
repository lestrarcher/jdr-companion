<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\Quest;
use App\Security\Voter\CampaignVoter;
use App\Repository\CampaignRepository;
use App\Repository\QuestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class QuestController extends AbstractController
{
    #[Route(
        '/campaigns/{campaignId}/quests',
        name: 'api_quest_list',
        requirements: ['campaignId' => '\d+'],
        methods: ['GET'],
    )]
    public function list(
        int $campaignId,
        CampaignRepository $campaignRepository,
        QuestRepository $questRepository,
    ): JsonResponse {
        $campaign = $this->getOwnedCampaign(
            $campaignId,
            $campaignRepository,
        );

        $quests = $questRepository->findBy(
            ['campaign' => $campaign],
            [
                'displayOrder' => 'ASC',
                'createdAt' => 'ASC',
            ],
        );

        return $this->json([
            'quests' => array_map(
                fn (Quest $quest): array =>
                    $this->serializeQuest($quest),
                $quests,
            ),
        ]);
    }

    #[Route(
        '/campaigns/{campaignId}/quests',
        name: 'api_quest_create',
        requirements: ['campaignId' => '\d+'],
        methods: ['POST'],
    )]
    public function create(
        int $campaignId,
        Request $request,
        CampaignRepository $campaignRepository,
        QuestRepository $questRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $campaign = $this->getOwnedCampaign(
            $campaignId,
            $campaignRepository,
        );

        $payload = $request->toArray();

        $title = trim(
            (string) ($payload['title'] ?? ''),
        );

        $objective = trim(
            (string) ($payload['objective'] ?? ''),
        );

        $category = trim(
            (string) ($payload['category'] ?? ''),
        );

        if (
            $title === ''
            || $objective === ''
            || $category === ''
        ) {
            return $this->json(
                [
                    'message' =>
                        'Le titre, l’objectif et la catégorie sont obligatoires.',
                ],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $lastQuest = $questRepository->findOneBy(
            ['campaign' => $campaign],
            ['displayOrder' => 'DESC'],
        );

        $displayOrder = $lastQuest instanceof Quest
            ? ((int) $lastQuest->getDisplayOrder()) + 10
            : 10;

        $quest = new Quest(
            campaign: $campaign,
            title: $title,
            objective: $objective,
            category: $category,
            displayOrder: $displayOrder,
        );

        $entityManager->persist($quest);
        $entityManager->flush();

        return $this->json(
            [
                'quest' =>
                    $this->serializeQuest($quest),
            ],
            JsonResponse::HTTP_CREATED,
        );
    }

    #[Route(
        '/quests/{questId}',
        name: 'api_quest_update',
        requirements: ['questId' => '\d+'],
        methods: ['PATCH'],
    )]
    public function update(
        int $questId,
        Request $request,
        QuestRepository $questRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $quest = $questRepository->find($questId);

        if (!$quest instanceof Quest) {
            throw $this->createNotFoundException(
                'Quête introuvable.',
            );
        }

        $campaign = $quest->getCampaign();

        if (!$campaign instanceof Campaign) {
            throw $this->createNotFoundException(
                'Campagne introuvable.',
            );
        }

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $campaign,
        );
        $payload = $request->toArray();

        if (array_key_exists('title', $payload)) {
            $title = trim(
                (string) $payload['title'],
            );

            if ($title === '') {
                return $this->json(
                    [
                        'message' =>
                            'Le titre ne peut pas être vide.',
                    ],
                    JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            $quest->setTitle($title);
        }

        if (array_key_exists('objective', $payload)) {
            $objective = trim(
                (string) $payload['objective'],
            );

            if ($objective === '') {
                return $this->json(
                    [
                        'message' =>
                            'L’objectif ne peut pas être vide.',
                    ],
                    JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            $quest->setObjective($objective);
        }

        if (array_key_exists('category', $payload)) {
            $category = trim(
                (string) $payload['category'],
            );

            if ($category === '') {
                return $this->json(
                    [
                        'message' =>
                            'La catégorie ne peut pas être vide.',
                    ],
                    JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            $quest->setCategory($category);
        }

        if (array_key_exists('status', $payload)) {
            $status = trim(
                (string) $payload['status'],
            );

            if (
                !in_array(
                    $status,
                    Quest::allowedStatuses(),
                    true,
                )
            ) {
                return $this->json(
                    [
                        'message' =>
                            'Le statut de la quête est invalide.',
                        'allowedStatuses' =>
                            Quest::allowedStatuses(),
                    ],
                    JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            $quest->setStatus($status);
        }

        if (array_key_exists('displayOrder', $payload)) {
            $displayOrder =
                filter_var(
                    $payload['displayOrder'],
                    FILTER_VALIDATE_INT,
                );

            if (
                $displayOrder === false
                || $displayOrder < 0
            ) {
                return $this->json(
                    [
                        'message' =>
                            'L’ordre d’affichage doit être un entier positif.',
                    ],
                    JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            $quest->setDisplayOrder(
                $displayOrder,
            );
        }

        $entityManager->flush();

        return $this->json([
            'quest' =>
                $this->serializeQuest($quest),
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
     * @return array{
     *     id: int|null,
     *     campaignId: int|null,
     *     title: string|null,
     *     objective: string|null,
     *     category: string|null,
     *     status: string|null,
     *     displayOrder: int|null,
     *     createdAt: string|null,
     *     updatedAt: string|null
     * }
     */
    private function serializeQuest(
        Quest $quest,
    ): array {
        return [
            'id' => $quest->getId(),
            'campaignId' =>
                $quest->getCampaign()?->getId(),
            'title' => $quest->getTitle(),
            'objective' =>
                $quest->getObjective(),
            'category' =>
                $quest->getCategory(),
            'status' => $quest->getStatus(),
            'displayOrder' =>
                $quest->getDisplayOrder(),
            'createdAt' =>
                $quest->getCreatedAt()
                    ?->format(DATE_ATOM),
            'updatedAt' =>
                $quest->getUpdatedAt()
                    ?->format(DATE_ATOM),
        ];
    }
}

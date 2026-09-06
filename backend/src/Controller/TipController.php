<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\Tip;
use App\Repository\CampaignRepository;
use App\Repository\TipRepository;
use App\Security\Voter\CampaignVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class TipController extends AbstractController
{
    #[Route(
        '/campaigns/{campaignId}/tips',
        name: 'api_tip_list',
        requirements: ['campaignId' => '\d+'],
        methods: ['GET'],
    )]
    public function list(
        int $campaignId,
        CampaignRepository $campaignRepository,
        TipRepository $tipRepository,
    ): JsonResponse {
        $campaign = $this->getOwnedCampaign(
            $campaignId,
            $campaignRepository,
        );

        $tips = $tipRepository->findBy(
            ['campaign' => $campaign],
            [
                'displayOrder' => 'ASC',
                'createdAt' => 'ASC',
            ],
        );

        return $this->json([
            'tips' => array_map(
                fn (Tip $tip): array =>
                    $this->serializeTip($tip),
                $tips,
            ),
        ]);
    }

    #[Route(
        '/campaigns/{campaignId}/tips',
        name: 'api_tip_create',
        requirements: ['campaignId' => '\d+'],
        methods: ['POST'],
    )]
    public function create(
        int $campaignId,
        Request $request,
        CampaignRepository $campaignRepository,
        TipRepository $tipRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $campaign = $this->getOwnedCampaign(
            $campaignId,
            $campaignRepository,
        );

        $payload = $request->toArray();

        $label = trim(
            (string) ($payload['label'] ?? ''),
        );

        $text = trim(
            (string) ($payload['text'] ?? ''),
        );

        $category = trim(
            (string) (
                $payload['category']
                ?? Tip::CATEGORY_RULE
            ),
        );

        if ($label === '' || $text === '') {
            return $this->json(
                [
                    'message' =>
                        'Le titre et le texte sont obligatoires.',
                ],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (
            !in_array(
                $category,
                Tip::allowedCategories(),
                true,
            )
        ) {
            return $this->json(
                [
                    'message' =>
                        'La catégorie du conseil est invalide.',
                    'allowedCategories' =>
                        Tip::allowedCategories(),
                ],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $lastTip = $tipRepository->findOneBy(
            ['campaign' => $campaign],
            ['displayOrder' => 'DESC'],
        );

        $displayOrder =
            $lastTip instanceof Tip
                ? ((int) $lastTip->getDisplayOrder()) + 10
                : 10;

        $tip = new Tip(
            campaign: $campaign,
            label: $label,
            text: $text,
            category: $category,
            displayOrder: $displayOrder,
        );

        $entityManager->persist($tip);
        $entityManager->flush();

        return $this->json(
            [
                'tip' =>
                    $this->serializeTip($tip),
            ],
            JsonResponse::HTTP_CREATED,
        );
    }

    #[Route(
        '/tips/{tipId}',
        name: 'api_tip_update',
        requirements: ['tipId' => '\d+'],
        methods: ['PATCH'],
    )]
    public function update(
        int $tipId,
        Request $request,
        TipRepository $tipRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $tip = $tipRepository->find($tipId);

        if (!$tip instanceof Tip) {
            throw $this->createNotFoundException(
                'Conseil introuvable.',
            );
        }

        $campaign = $tip->getCampaign();

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

        try {
            if (array_key_exists('label', $payload)) {
                $label = trim(
                    (string) $payload['label'],
                );

                if ($label === '') {
                    throw new \InvalidArgumentException(
                        'Le titre ne peut pas être vide.',
                    );
                }

                $tip->setLabel($label);
            }

            if (array_key_exists('text', $payload)) {
                $text = trim(
                    (string) $payload['text'],
                );

                if ($text === '') {
                    throw new \InvalidArgumentException(
                        'Le texte ne peut pas être vide.',
                    );
                }

                $tip->setText($text);
            }

            if (array_key_exists('category', $payload)) {
                $tip->setCategory(
                    (string) $payload['category'],
                );
            }

            if (array_key_exists('status', $payload)) {
                $tip->setStatus(
                    (string) $payload['status'],
                );
            }

            if (array_key_exists('displayOrder', $payload)) {
                $displayOrder = filter_var(
                    $payload['displayOrder'],
                    FILTER_VALIDATE_INT,
                );

                if (
                    $displayOrder === false
                    || $displayOrder < 0
                ) {
                    throw new \InvalidArgumentException(
                        'L’ordre d’affichage doit être positif.',
                    );
                }

                $tip->setDisplayOrder(
                    $displayOrder,
                );
            }
        } catch (\InvalidArgumentException $exception) {
            return $this->json(
                [
                    'message' =>
                        $exception->getMessage(),
                ],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $entityManager->flush();

        return $this->json([
            'tip' =>
                $this->serializeTip($tip),
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
     *     label: string|null,
     *     text: string|null,
     *     category: string|null,
     *     status: string|null,
     *     displayOrder: int|null,
     *     createdAt: string|null,
     *     updatedAt: string|null
     * }
     */
    private function serializeTip(
        Tip $tip,
    ): array {
        return [
            'id' => $tip->getId(),
            'campaignId' =>
                $tip->getCampaign()?->getId(),
            'label' => $tip->getLabel(),
            'text' => $tip->getText(),
            'category' => $tip->getCategory(),
            'status' => $tip->getStatus(),
            'displayOrder' =>
                $tip->getDisplayOrder(),
            'createdAt' =>
                $tip->getCreatedAt()
                    ?->format(DATE_ATOM),
            'updatedAt' =>
                $tip->getUpdatedAt()
                    ?->format(DATE_ATOM),
        ];
    }
}

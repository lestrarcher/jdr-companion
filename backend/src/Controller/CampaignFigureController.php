<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\CampaignFigure;
use App\Entity\Character;
use App\Repository\CharacterRepository;
use App\Entity\Media;
use App\Repository\CampaignFigureRepository;
use App\Repository\CampaignRepository;
use App\Repository\MediaRepository;
use App\Security\Voter\CampaignVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CampaignFigureController extends AbstractController
{
    #[Route(
        '/campaigns/{campaignId}/figures',
        name: 'api_campaign_figure_list',
        requirements: ['campaignId' => '\d+'],
        methods: ['GET'],
    )]
    public function list(
        int $campaignId,
        CampaignRepository $campaignRepository,
        CampaignFigureRepository $figureRepository,
    ): JsonResponse {
        $campaign = $this->getOwnedCampaign(
            $campaignId,
            $campaignRepository,
        );

        $figures = $figureRepository->findBy(
            ['campaign' => $campaign],
            [
                'displayOrder' => 'ASC',
                'createdAt' => 'ASC',
            ],
        );

        return $this->json([
            'figures' => array_map(
                fn (CampaignFigure $figure): array =>
                    $this->serializeFigure($figure),
                $figures,
            ),
        ]);
    }

    #[Route(
        '/campaigns/{campaignId}/figures',
        name: 'api_campaign_figure_create',
        requirements: ['campaignId' => '\d+'],
        methods: ['POST'],
    )]
    public function create(
        int $campaignId,
        Request $request,
        CampaignRepository $campaignRepository,
        CampaignFigureRepository $figureRepository,
        MediaRepository $mediaRepository,
        EntityManagerInterface $entityManager,
        CharacterRepository $characterRepository,
    ): JsonResponse {
        $campaign = $this->getOwnedCampaign(
            $campaignId,
            $campaignRepository,
        );

        $payload = $request->toArray();

        $name = trim(
            (string) ($payload['name'] ?? ''),
        );

        $description = trim(
            (string) ($payload['description'] ?? ''),
        );

        $characterType = trim(
            (string) (
                $payload['characterType']
                ?? CampaignFigure::TYPE_NPC
            ),
        );

        if ($name === '' || $description === '') {
            return $this->json(
                [
                    'message' =>
                        'Le nom et la description sont obligatoires.',
                ],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (
            !in_array(
                $characterType,
                CampaignFigure::allowedCharacterTypes(),
                true,
            )
        ) {
            return $this->json(
                [
                    'message' =>
                        'Le type de personnage est invalide.',
                ],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $lastFigure = $figureRepository->findOneBy(
            ['campaign' => $campaign],
            ['displayOrder' => 'DESC'],
        );

        $displayOrder =
            $lastFigure instanceof CampaignFigure
                ? ((int) $lastFigure->getDisplayOrder()) + 10
                : 10;

        $figure = new CampaignFigure(
            campaign: $campaign,
            name: $name,
            characterType: $characterType,
            description: $description,
            displayOrder: $displayOrder,
        );

        if (array_key_exists('deathLabel', $payload)) {
            $figure->setDeathLabel(
                $this->nullableString(
                    $payload['deathLabel'],
                ),
            );
        }

        if (array_key_exists('encounterStatus', $payload)) {
            $figure->setEncounterStatus(
                (string) $payload['encounterStatus'],
            );
        }

        if (array_key_exists('lifeStatus', $payload)) {
            $figure->setLifeStatus(
                (string) $payload['lifeStatus'],
            );
        }

        if (array_key_exists('portraitId', $payload)) {
            $figure->setPortrait(
                $this->resolvePortrait(
                    $payload['portraitId'],
                    $campaign,
                    $mediaRepository,
                ),
            );
        }

        if (array_key_exists('characterId', $payload)) {
            $figure->setCharacter(
                $this->resolveCharacter(
                    $payload['characterId'],
                    $campaign,
                    $characterRepository,
                ),
            );
        }

        $entityManager->persist($figure);
        $entityManager->flush();

        return $this->json(
            [
                'figure' =>
                    $this->serializeFigure($figure),
            ],
            JsonResponse::HTTP_CREATED,
        );
    }

    #[Route(
        '/figures/{figureId}',
        name: 'api_campaign_figure_update',
        requirements: ['figureId' => '\d+'],
        methods: ['PATCH'],
    )]
    public function update(
        int $figureId,
        Request $request,
        CampaignFigureRepository $figureRepository,
        MediaRepository $mediaRepository,
        CharacterRepository $characterRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $figure = $figureRepository->find($figureId);

        if (!$figure instanceof CampaignFigure) {
            throw $this->createNotFoundException(
                'Figure de campagne introuvable.',
            );
        }

        $campaign = $figure->getCampaign();

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
            if (array_key_exists('name', $payload)) {
                $name = trim(
                    (string) $payload['name'],
                );

                if ($name === '') {
                    throw new \InvalidArgumentException(
                        'Le nom ne peut pas être vide.',
                    );
                }

                $figure->setName($name);
            }

            if (array_key_exists('description', $payload)) {
                $description = trim(
                    (string) $payload['description'],
                );

                if ($description === '') {
                    throw new \InvalidArgumentException(
                        'La description ne peut pas être vide.',
                    );
                }

                $figure->setDescription($description);
            }

            if (array_key_exists('characterType', $payload)) {
                $figure->setCharacterType(
                    (string) $payload['characterType'],
                );
            }

            if (array_key_exists('encounterStatus', $payload)) {
                $figure->setEncounterStatus(
                    (string) $payload['encounterStatus'],
                );
            }

            if (array_key_exists('lifeStatus', $payload)) {
                $figure->setLifeStatus(
                    (string) $payload['lifeStatus'],
                );
            }

            if (array_key_exists('publicationStatus', $payload)) {
                $figure->setPublicationStatus(
                    (string) $payload['publicationStatus'],
                );
            }

            if (array_key_exists('deathLabel', $payload)) {
                $figure->setDeathLabel(
                    $this->nullableString(
                        $payload['deathLabel'],
                    ),
                );
            }

            if (array_key_exists('portraitId', $payload)) {
                $figure->setPortrait(
                    $this->resolvePortrait(
                        $payload['portraitId'],
                        $campaign,
                        $mediaRepository,
                    ),
                );
            }

            if (array_key_exists('characterId', $payload)) {
                $figure->setCharacter(
                    $this->resolveCharacter(
                        $payload['characterId'],
                        $campaign,
                        $characterRepository,
                    ),
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

                $figure->setDisplayOrder(
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
            'figure' =>
                $this->serializeFigure($figure),
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

    private function resolveCharacter(
        mixed $characterId,
        Campaign $campaign,
        CharacterRepository $characterRepository,
    ): ?Character {
        if (
            $characterId === null
            || $characterId === ''
        ) {
            return null;
        }

        $id = filter_var(
            $characterId,
            FILTER_VALIDATE_INT,
        );

        if ($id === false || $id <= 0) {
            throw new \InvalidArgumentException(
                'Le personnage sélectionné est invalide.',
            );
        }

        $character = $characterRepository->find($id);

        if (
            !$character instanceof Character
            || $character->getCampaign()->getId()
                !== $campaign->getId()
        ) {
            throw new \InvalidArgumentException(
                'Ce personnage n’appartient pas à la campagne.',
            );
        }

        return $character;
    }

    private function resolvePortrait(
        mixed $portraitId,
        Campaign $campaign,
        MediaRepository $mediaRepository,
    ): ?Media {
        if (
            $portraitId === null
            || $portraitId === ''
        ) {
            return null;
        }

        $id = filter_var(
            $portraitId,
            FILTER_VALIDATE_INT,
        );

        if ($id === false || $id <= 0) {
            throw new \InvalidArgumentException(
                'Le portrait sélectionné est invalide.',
            );
        }

        $portrait = $mediaRepository->find($id);

        if (
            !$portrait instanceof Media
            || $portrait->getCampaign()?->getId()
                !== $campaign->getId()
            || $portrait->getUsage()
                !== Media::USAGE_PORTRAIT
        ) {
            throw new \InvalidArgumentException(
                'Ce portrait n’appartient pas à la campagne.',
            );
        }

        return $portrait;
    }

    private function nullableString(
        mixed $value,
    ): ?string {
        $value = trim((string) $value);

        return $value !== ''
            ? $value
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFigure(
        CampaignFigure $figure,
    ): array {
        $portrait = $figure->getPortrait();

        return [
            'id' => $figure->getId(),
            'campaignId' => $figure->getCampaign()?->getId(),
            'characterId' => $figure->getCharacter()?->getId(),
            'name' => $figure->getName(),
            'characterType' => $figure->getCharacterType(),
            'encounterStatus' => $figure->getEncounterStatus(),
            'lifeStatus' => $figure->getLifeStatus(),
            'description' => $figure->getDescription(),
            'deathLabel' => $figure->getDeathLabel(),
            'publicationStatus' => $figure->getPublicationStatus(),
            'displayOrder' => $figure->getDisplayOrder(),

            'portrait' => $portrait
                ? [
                    'id' => $portrait->getId(),
                    'title' => $portrait->getTitle(),
                    'originalName' =>
                        $portrait->getOriginalName(),
                    'url' => sprintf(
                        '/api/public/media/%s',
                        $portrait->getFilename(),
                    ),
                ]
                : null,

            'createdAt' => $figure->getCreatedAt()?->format(DATE_ATOM),
            'updatedAt' => $figure->getUpdatedAt()?->format(DATE_ATOM),
        ];
    }
}

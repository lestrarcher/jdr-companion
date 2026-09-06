<?php

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\Media;
use App\Repository\CampaignRepository;
use App\Repository\CampaignFigureRepository;
use App\Repository\MediaRepository;
use App\Security\Voter\CampaignVoter;
use App\Service\MediaStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/campaigns/{campaignId}/media')]
final class MediaController extends AbstractController
{
    public function __construct(
        private readonly CampaignRepository $campaignRepository,
        private readonly MediaRepository $mediaRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaStorageService $mediaStorage,
    ) {
    }

    #[Route('', name: 'api_media_list', methods: ['GET'])]
    public function list(
        int $campaignId,
        Request $request,
    ): JsonResponse {
        $campaign =
            $this->getCampaign($campaignId);

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $campaign,
        );

        $usage = (string) $request->query->get(
            'usage',
            Media::USAGE_SCENE,
        );

        if (!in_array($usage, Media::allowedUsages(), true)) {
            return $this->json(
                ['message' => 'Le type de média demandé est invalide.'],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $media = $this->mediaRepository->findBy(
            [
                'campaign' => $campaign,
                'usage' => $usage,
            ],
            ['createdAt' => 'DESC'],
        );

        return $this->json([
            'media' => array_map(
                fn (Media $item): array =>
                    $this->serializeMedia($item),
                $media,
            ),
        ]);
    }

    #[Route('', name: 'api_media_upload', methods: ['POST'])]
    public function upload(
        int $campaignId,
        Request $request,
    ): JsonResponse {
        $campaign =
            $this->getCampaign($campaignId);

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $campaign,
        );

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return $this->json(
                [
                    'message' =>
                        'Aucun fichier image n’a été envoyé.',
                ],
                400,
            );
        }

        if (!$file->isValid()) {
            return $this->json(
                [
                    'message' => sprintf(
                        'Upload invalide : %s',
                        $file->getErrorMessage(),
                    ),
                    'uploadErrorCode' =>
                        $file->getError(),
                ],
                422,
            );
        }

        $originalName =
            $file->getClientOriginalName();

        $mimeType =
            $file->getMimeType() ??
            'application/octet-stream';

        $size = $file->getSize();

        if ($size === false) {
            return $this->json(
                [
                    'message' =>
                        'Impossible de déterminer la taille du fichier.',
                ],
                400,
            );
        }

        try {
            $filename =
                $this->mediaStorage->store(
                    $file,
                    $campaignId,
                );
        } catch (\InvalidArgumentException $exception) {
            return $this->json(
                [
                    'message' =>
                        $exception->getMessage(),
                ],
                422,
            );
        } catch (\RuntimeException $exception) {
            return $this->json(
                [
                    'message' =>
                        $exception->getMessage(),
                ],
                500,
            );
        }

        $title = trim(
            (string) $request->request->get(
                'title',
                '',
            ),
        );

        $usage = trim(
            (string) $request->request->get(
                'usage',
                Media::USAGE_SCENE,
            ),
        );

        if (!in_array($usage, Media::allowedUsages(), true)) {
            $this->mediaStorage->remove($filename, $campaignId);

            return $this->json(
                ['message' => 'Le type de média est invalide.'],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $media = new Media();

        $media
            ->setCampaign($campaign)
            ->setFilename($filename)
            ->setOriginalName($originalName)
            ->setMimeType($mimeType)
            ->setSize($size)
            ->setUsage($usage)
            ->setTitle(
                $title !== ''
                    ? $title
                    : null,
            );

        try {
            $this->entityManager->persist($media);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->mediaStorage->remove($filename, $campaignId);

            throw $exception;
        }

        return $this->json(
            [
                'media' =>
                    $this->serializeMedia($media),
            ],
            201,
        );
    }

    #[Route('/{mediaId}', name: 'api_media_delete', requirements: ['mediaId' => '\\d+'], methods: ['DELETE'])]
    public function delete(
        int $campaignId,
        int $mediaId,
        CampaignFigureRepository $figureRepository,
    ): JsonResponse {
        $campaign = $this->getCampaign($campaignId);

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $campaign,
        );

        $media = $this->mediaRepository->find($mediaId);

        if (
            !$media instanceof Media
            || $media->getCampaign()?->getId() !== $campaign->getId()
        ) {
            throw $this->createNotFoundException(
                'Média introuvable.',
            );
        }

        if ($figureRepository->findOneBy(['portrait' => $media])) {
            return $this->json(
                [
                    'message' =>
                        'Ce portrait est encore utilisé par une personne.',
                ],
                JsonResponse::HTTP_CONFLICT,
            );
        }

        $filename = $media->getFilename();

        if ($filename === null) {
            throw $this->createNotFoundException(
                'Fichier média introuvable.',
            );
        }

        $this->mediaStorage->remove($filename, $campaignId);
        $this->entityManager->remove($media);
        $this->entityManager->flush();

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    private function getCampaign(
        int $campaignId,
    ): Campaign {
        $campaign =
            $this->campaignRepository->find(
                $campaignId,
            );

        if (!$campaign) {
            throw $this->createNotFoundException(
                'Campagne introuvable.',
            );
        }

        return $campaign;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMedia(
        Media $media,
    ): array {

        return [
            'id' => $media->getId(),
            'title' => $media->getTitle(),
            'originalName' =>
                $media->getOriginalName(),
            'mimeType' => $media->getMimeType(),
            'size' => $media->getSize(),
            'usage' => $media->getUsage(),

            'url' => sprintf(
                '/api/public/media/%s',
                $media->getFilename(),
            ),

            'createdAt' =>
                $media
                    ->getCreatedAt()
                    ?->format(DATE_ATOM),
        ];
    }
}

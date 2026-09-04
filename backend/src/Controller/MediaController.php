<?php

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\Media;
use App\Repository\CampaignRepository;
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
    ): JsonResponse {
        $campaign =
            $this->getCampaign($campaignId);

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $campaign,
        );

        $media = $this->mediaRepository->findBy(
            ['campaign' => $campaign],
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

        $media = new Media();

        $media
            ->setCampaign($campaign)
            ->setFilename($filename)
            ->setOriginalName($originalName)
            ->setMimeType($mimeType)
            ->setSize($size)
            ->setTitle(
                $title !== ''
                    ? $title
                    : null,
            );

        $this->entityManager->persist($media);
        $this->entityManager->flush();

        return $this->json(
            [
                'media' =>
                    $this->serializeMedia($media),
            ],
            201,
        );
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

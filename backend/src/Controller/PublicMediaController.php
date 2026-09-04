<?php

namespace App\Controller;

use App\Repository\MediaRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class PublicMediaController extends AbstractController
{
    public function __construct(
        private readonly MediaRepository $mediaRepository,
        private readonly string $projectDir,
    ) {
    }

    #[Route(
        '/public/media/{filename}',
        name: 'api_public_media_show',
        methods: ['GET'],
    )]
    public function show(
        string $filename,
    ): BinaryFileResponse {
        $media =
            $this->mediaRepository->findOneBy([
                'filename' => $filename,
            ]);

        if (!$media) {
            throw $this->createNotFoundException(
                'Média introuvable.',
            );
        }

        $campaignId =
            $media->getCampaign()?->getId();

        $path = sprintf(
            '%s/public/uploads/campaigns/%d/%s',
            $this->projectDir,
            $campaignId,
            $media->getFilename(),
        );

        if (!is_file($path)) {
            throw $this->createNotFoundException(
                'Fichier média introuvable.',
            );
        }

        $response = new BinaryFileResponse(
            $path,
        );

        $response->headers->set(
            'Content-Type',
            $media->getMimeType(),
        );

        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $media->getOriginalName(),
        );

        return $response;
    }
}

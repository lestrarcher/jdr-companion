<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BeastRepository;
use App\Service\MediaStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{BinaryFileResponse, Response};
use Symfony\Component\Routing\Attribute\Route;

final class PublicBeastImageController extends AbstractController
{
    public function __construct(private readonly BeastRepository $beasts, private readonly MediaStorageService $storage, private readonly string $projectDir) {}

    #[Route('/public/beasts/images/{filename}/thumbnail', name: 'api_public_beast_thumbnail', requirements: ['filename' => '[a-f0-9]{32}\.(?:jpg|png|webp|gif)'], methods: ['GET'])]
    public function thumbnail(string $filename): BinaryFileResponse
    {
        if (!$this->beasts->findOneBy(['imageFilename' => $filename])) throw $this->createNotFoundException('Illustration inconnue.');
        try { $path = $this->storage->beastThumbnail($filename); }
        catch (\InvalidArgumentException|\RuntimeException) { throw $this->createNotFoundException('Miniature indisponible.'); }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'image/webp');
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    #[Route('/public/beasts/images/{filename}', name: 'api_public_beast_image', requirements: ['filename' => '[a-f0-9]{32}\.(?:jpg|png|webp|gif)'], methods: ['GET'])]
    public function show(string $filename): BinaryFileResponse
    {
        if (!$this->beasts->findOneBy(['imageFilename' => $filename])) {
            throw $this->createNotFoundException('Illustration inconnue.');
        }
        $path = $this->projectDir . '/public/uploads/beasts/' . $filename;
        if (!is_file($path) || is_link($path)) throw $this->createNotFoundException('Fichier introuvable.');
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', match (pathinfo($filename, PATHINFO_EXTENSION)) {
            'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
        });
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{BinaryFileResponse, Response};
use Symfony\Component\Routing\Attribute\Route;

final class PublicBeastImageController extends AbstractController
{
    public function __construct(private readonly Connection $db, private readonly string $projectDir) {}

    #[Route('/public/beasts/images/{filename}', name: 'api_public_beast_image', requirements: ['filename' => '[a-f0-9]{32}\.(?:jpg|png|webp|gif)'], methods: ['GET'])]
    public function show(string $filename): BinaryFileResponse
    {
        if (!$this->db->fetchOne('SELECT 1 FROM beast WHERE image_filename = :filename', ['filename' => $filename])) {
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

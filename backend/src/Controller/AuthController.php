<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends AbstractController
{
    #[Route(
        '/login',
        name: 'api_login',
        methods: ['POST'],
    )]
    public function login(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(
                [
                    'message' => 'Authentification requise.',
                ],
                JsonResponse::HTTP_UNAUTHORIZED,
            );
        }

        return $this->json([
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getUserIdentifier(),
                'roles' => $user->getRoles(),
            ],
        ]);
    }

    #[Route(
        '/me',
        name: 'api_me',
        methods: ['GET'],
    )]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(
                [
                    'message' => 'Authentification requise.',
                ],
                JsonResponse::HTTP_UNAUTHORIZED,
            );
        }

        return $this->json([
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getUserIdentifier(),
                'roles' => $user->getRoles(),
            ],
        ]);
    }

    #[Route(
        '/logout',
        name: 'api_logout',
        methods: ['POST'],
    )]
    public function logout(): never
    {
        /*
         * Symfony intercepte cette route avant
         * l’exécution du contrôleur.
         */
        throw new \LogicException(
            'Cette méthode est interceptée par le firewall.',
        );
    }
}

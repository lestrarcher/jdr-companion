<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\MoonPhase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class MoonPhaseController extends AbstractController
{
    #[Route(
        '/moon-phases',
        name: 'api_moon_phases_list',
        methods: ['GET'],
    )]
    public function list(): JsonResponse
    {
        return $this->json([
            'moonPhases' => array_map(
                static fn (MoonPhase $phase): array =>
                    $phase->toArray(),
                MoonPhase::cases(),
            ),
        ]);
    }
}

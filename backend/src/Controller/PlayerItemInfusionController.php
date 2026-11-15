<?php

declare(strict_types=1);
namespace App\Controller;

use App\Service\{ItemInfusions, PlayerCharacterAccess};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/characters/{accessToken}/infusions', requirements: ['accessToken'=>'[a-f0-9]{64}'])]
final class PlayerItemInfusionController extends AbstractController
{
    public function __construct(private readonly PlayerCharacterAccess $access, private readonly ItemInfusions $infusions) {}

    #[Route('', name: 'api_public_infusions', methods: ['GET'])]
    public function show(string $accessToken): JsonResponse
    {
        $actor = $this->access->requireParticipating($accessToken);
        return $this->json($this->infusions->view($actor->getCharacter()) + ['preparation'=>$this->infusions->preparation($actor)]);
    }

    #[Route('/preparation/confirm', name: 'api_public_infusions_confirm', methods: ['POST'])]
    public function confirm(string $accessToken, Request $request): JsonResponse
    {
        $actor = $this->access->requireParticipating($accessToken);
        if ($request->getContentTypeFormat() !== 'json' || !str_starts_with(ltrim($request->getContent()), '{')) throw new BadRequestHttpException('Objet JSON requis.');
        $data = $request->toArray();
        if (array_diff(array_keys($data), ['phaseId', 'selections']) || !is_string($data['phaseId'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $data['phaseId'])
            || (array_key_exists('selections', $data) && !is_array($data['selections']))) throw new BadRequestHttpException('Identifiant de phase et liste de sélections invalides.');
        return $this->json($this->infusions->finishPreparation($actor, $data['phaseId'], $data['selections'] ?? null));
    }

    #[Route('/recipients', name: 'api_public_infusions_recipients', methods: ['GET'])]
    public function recipients(string $accessToken): JsonResponse
    {
        return $this->json(['recipients'=>$this->infusions->recipients($this->access->requireParticipating($accessToken)->getCharacter())]);
    }

    #[Route('/recipients/{recipientId}/items', name: 'api_public_infusions_items', requirements: ['recipientId'=>'\d+'], methods: ['GET'])]
    public function items(string $accessToken, int $recipientId, Request $request): JsonResponse
    {
        $choice = $request->query->get('choiceKey');
        if (!is_string($choice) || !preg_match('/^[a-f0-9]{32}$/D', $choice)) throw new BadRequestHttpException('Choix requis.');
        return $this->json(['items'=>$this->infusions->items($this->access->requireParticipating($accessToken)->getCharacter(), $recipientId, $choice)]);
    }

    #[Route('', name: 'api_public_infusions_apply', methods: ['POST'])]
    public function apply(string $accessToken, Request $request): JsonResponse
    {
        $this->access->requireParticipating($accessToken);
        throw new ConflictHttpException('Préparez vos imprégnations puis confirmez tous les changements ensemble.');
    }

    #[Route('/{id}', name: 'api_public_infusions_remove', requirements: ['id'=>'\d+'], methods: ['DELETE'])]
    public function remove(string $accessToken, int $id): JsonResponse
    {
        $this->access->requireParticipating($accessToken);
        throw new ConflictHttpException('Préparez vos imprégnations puis confirmez tous les changements ensemble.');
    }
}

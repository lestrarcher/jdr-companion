<?php
declare(strict_types=1);
namespace App\Controller;
use App\Service\{BeastCatalogue, PlayerCharacterAccess};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[Route('/public/characters/{accessToken}/beasts', requirements: ['accessToken' => '[a-f0-9]{64}'])]
final class PublicBeastCatalogueController extends AbstractController
{
    public function __construct(private readonly PlayerCharacterAccess $access, private readonly BeastCatalogue $beasts) {}
    #[Route('', name: 'api_public_beasts_list', methods: ['GET'])]
    public function list(string $accessToken): JsonResponse
    {
        $characterId = $this->access->requireParticipating($accessToken)->getCharacter()->getId();
        return $this->json(['beasts' => $this->beasts->listForCharacter($characterId)]);
    }
    #[Route('/{id}/seen', name: 'api_public_beasts_seen', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function setSeen(string $accessToken, int $id, Request $request): JsonResponse
    {
        $characterId = $this->access->requireParticipating($accessToken)->getCharacter()->getId();
        try { $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new BadRequestHttpException('JSON invalide.'); }
        if (!is_array($payload) || !array_key_exists('seen', $payload) || !is_bool($payload['seen'])) throw new BadRequestHttpException('Le champ seen doit être un booléen.');
        $this->beasts->setSeen($characterId, $id, $payload['seen']);
        return $this->json(['id' => $id, 'seen' => $payload['seen']]);
    }
    #[Route('/seen/bulk', name: 'api_public_beasts_seen_bulk', methods: ['PUT'])]
    public function setSeenBulk(string $accessToken, Request $request): JsonResponse
    {
        $characterId = $this->access->requireParticipating($accessToken)->getCharacter()->getId();
        try { $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new BadRequestHttpException('JSON invalide.'); }
        $ids = $payload['ids'] ?? null;
        if (!is_array($payload) || !is_array($ids) || !array_is_list($ids) || count($ids) > 100
            || !array_key_exists('seen', $payload) || !is_bool($payload['seen'])) {
            throw new BadRequestHttpException('ids (maximum 100) et seen requis.');
        }
        foreach ($ids as $id) if (!is_int($id) || $id < 1) throw new BadRequestHttpException('Identifiant de bête invalide.');
        return $this->json(['updated' => $this->beasts->setSeenBulk($characterId, $ids, $payload['seen'])]);
    }

}

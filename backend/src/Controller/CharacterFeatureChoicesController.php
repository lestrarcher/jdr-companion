<?php

declare(strict_types=1);
namespace App\Controller;

use App\Entity\{Character, User};
use App\Security\Voter\CampaignVoter;
use App\Service\{CharacterFeatureChoices, PlayerCharacterAccess};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, UnprocessableEntityHttpException};
use Symfony\Component\Routing\Attribute\Route;

final class CharacterFeatureChoicesController extends AbstractController
{
    public function __construct(private CharacterFeatureChoices $choices, private EntityManagerInterface $em) {}

    #[Route('/campaigns/{campaignId}/characters/{characterId}/feature-choices', requirements: ['campaignId'=>'\d+', 'characterId'=>'\d+'], methods: ['GET'])]
    public function show(int $campaignId, int $characterId): JsonResponse
    {
        return $this->json($this->choices->preview($this->character($campaignId,$characterId)));
    }

    #[Route('/campaigns/{campaignId}/characters/{characterId}/feature-choices', requirements: ['campaignId'=>'\d+', 'characterId'=>'\d+'], methods: ['POST'])]
    public function save(int $campaignId, int $characterId, Request $request): JsonResponse
    {
        $character = $this->character($campaignId,$characterId);
        if ($request->getContentTypeFormat() !== 'json' || !str_starts_with(ltrim($request->getContent()), '{')) throw new BadRequestHttpException('Objet JSON requis.');
        $data = $request->toArray();
        if (array_diff(array_keys($data), ['fingerprint','selections','reason','pactTomeBookId'])
            || !is_string($data['fingerprint'] ?? null) || !is_string($data['reason'] ?? null)) throw new BadRequestHttpException('Commande invalide.');
        if (array_key_exists('pactTomeBookId',$data) && (!is_int($data['pactTomeBookId']) || $data['pactTomeBookId'] < 1)) throw new BadRequestHttpException('Identifiant du livre invalide.');
        try {
            $rows = CharacterFeatureChoices::selections($data['selections'] ?? null);
            return $this->json($this->choices->regularize($character, $this->getUser(), $data['fingerprint'], $rows, $data['reason'], $data['pactTomeBookId'] ?? null));
        } catch (\InvalidArgumentException|\DomainException $error) {
            throw new UnprocessableEntityHttpException($error->getMessage(), $error);
        }
    }

    #[Route('/public/characters/{accessToken}/feature-choices', requirements: ['accessToken'=>'[a-f0-9]{64}'], methods: ['GET'])]
    public function player(string $accessToken, PlayerCharacterAccess $access): JsonResponse
    {
        // Player writes use the existing authorized level-up transaction; historical repair is GM-only.
        $result = $this->choices->preview($access->requireParticipating($accessToken)->getCharacter());
        foreach ($result['history'] as &$row) { unset($row['actor_id']); }
        return $this->json($result);
    }

    #[Route('/campaigns/{campaignId}/characters/{characterId}/feature-choices/preview', requirements: ['campaignId'=>'\d+', 'characterId'=>'\d+'], methods: ['POST'])]
    public function preview(int $campaignId, int $characterId, Request $request): JsonResponse
    {
        return $this->project($this->character($campaignId,$characterId),$request);
    }

    #[Route('/public/characters/{accessToken}/feature-choices/preview', requirements: ['accessToken'=>'[a-f0-9]{64}'], methods: ['POST'])]
    public function playerPreview(string $accessToken, Request $request, PlayerCharacterAccess $access): JsonResponse
    {
        $state = $access->requireParticipating($accessToken);
        if (!$state->isLevelUpAllowed() || $state->getGameSession()->getStatus() !== \App\Entity\GameSession::STATUS_LIVE) throw $this->createAccessDeniedException();
        return $this->project($state->getCharacter(),$request);
    }

    private function project(Character $character, Request $request): JsonResponse
    {
        if ($request->getContentTypeFormat() !== 'json') throw new BadRequestHttpException('Objet JSON requis.');
        $data = $request->toArray();
        if (array_diff(array_keys($data),['classKey','subclassKey']) || !is_string($data['classKey'] ?? null)
            || (isset($data['subclassKey']) && !is_string($data['subclassKey']))) throw new BadRequestHttpException('Classe invalide.');
        try { return $this->json($this->choices->previewLevel($character,$data['classKey'],$data['subclassKey'] ?? null)); }
        catch (\DomainException $error) { throw new UnprocessableEntityHttpException($error->getMessage(),$error); }
    }

    private function character(int $campaignId, int $characterId): Character
    {
        $character = $this->em->find(Character::class, $characterId);
        if (!$character || $character->getCampaign()->getId() !== $campaignId) throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(CampaignVoter::MANAGE, $character->getCampaign());
        return $character;
    }
}

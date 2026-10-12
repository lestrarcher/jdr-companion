<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\Character;
use App\Security\Voter\CampaignVoter;
use App\Service\{CharacterTrainingService, CharacterTrainingPresenter};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

final class CharacterTrainingController extends AbstractController
{
    #[Route('/characters/{id}/training', name: 'api_character_training', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Character $character, CharacterTrainingPresenter $presenter): JsonResponse
    {
        $this->denyAccessUnlessGranted(CampaignVoter::VIEW, $character->getCampaign());
        return $this->json($presenter->resolved($character));
    }

    #[Route('/characters/{id}/manual-grants', name: 'api_character_manual_grants', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function grant(Character $character, Request $request, EntityManagerInterface $em, CharacterTrainingService $service, CharacterTrainingPresenter $presenter): JsonResponse
    {
        $this->denyAccessUnlessGranted(CampaignVoter::MANAGE, $character->getCampaign());
        try {
            $payload = $request->toArray();
            if (array_keys($payload) !== ['manualGrants'] || !is_array($payload['manualGrants']) || $payload['manualGrants'] === []) throw new \DomainException('Une liste manualGrants non vide est requise.');
            $em->wrapInTransaction(function () use ($em, $character, $payload, $service): void {
                $em->lock($character, LockMode::PESSIMISTIC_WRITE);
                $service->apply($character, $payload, $character->getCampaign()->getOwner(), false);
            });
            return $this->json($presenter->resolved($character), 201);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage()], 422);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CharacterSessionStateRepository;
use App\Security\Voter\CampaignVoter;
use App\Service\CharacterInspirationService;
use App\Service\CharacterSessionStateSerializer;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

final class CharacterInspirationController extends AbstractController
{
    #[Route('/sessions/{sessionId}/characters/{characterId}/inspiration', name: 'api_character_inspiration_update', requirements: ['sessionId' => '\d+', 'characterId' => '\d+'], methods: ['PATCH'])]
    public function update(
        int $sessionId,
        int $characterId,
        Request $request,
        CharacterSessionStateRepository $states,
        EntityManagerInterface $em,
        CharacterInspirationService $inspiration,
        CharacterSessionStateSerializer $serializer,
    ): JsonResponse {
        $state = $states->findOneBy(['gameSession' => $sessionId, 'character' => $characterId]);
        if ($state === null) return $this->json(['message' => 'Personnage de session introuvable.'], 404);
        $this->denyAccessUnlessGranted(CampaignVoter::MANAGE, $state->getGameSession()->getCampaign());
        if ($state->getCharacter()->getCampaign() !== $state->getGameSession()->getCampaign()) {
            return $this->json(['message' => 'Le personnage ne fait pas partie de cette campagne.'], 404);
        }
        try {
            $payload = $request->toArray();
        } catch (\JsonException) {
            return $this->json(['message' => 'Le corps JSON est invalide.'], 400);
        }
        $value = $payload['inspiration'] ?? null;
        $revision = $payload['revision'] ?? null;
        if (!is_bool($value) || !is_int($revision) || $revision < 1
            || array_diff(array_keys($payload), ['inspiration', 'revision'])) {
            return $this->json(['message' => 'Une inspiration booléenne et une révision entière positive sont requises.'], 422);
        }
        try {
            return $em->wrapInTransaction(function () use ($em, $state, $value, $revision, $inspiration, $serializer): JsonResponse {
                // Same lock order as PlayerCharacterAccess; a GM may also prepare a non-live session.
                $em->lock($state->getCharacter(), LockMode::PESSIMISTIC_WRITE);
                $em->refresh($state->getCharacter());
                $em->lock($state->getGameSession(), LockMode::PESSIMISTIC_READ);
                $em->refresh($state->getGameSession());
                $em->lock($state, LockMode::PESSIMISTIC_WRITE);
                $em->refresh($state);
                if (!$state->isParticipating()) return $this->json(['message' => 'Ce personnage ne participe pas à cette session.'], 404);
                if ($state->getRevision() !== $revision) return $this->json(['message' => 'Le personnage a été modifié ailleurs. Rechargez son état.'], 409);
                $inspiration->changeLocked($state->getCharacter(), $value);
                $em->flush();
                return $this->json($serializer->serialize($state, true));
            });
        } catch (OptimisticLockException) {
            return $this->json(['message' => 'Le personnage a été modifié ailleurs. Rechargez son état.'], 409);
        }
    }
}

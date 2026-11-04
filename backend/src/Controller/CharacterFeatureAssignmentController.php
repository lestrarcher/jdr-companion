<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\Character;
use App\Entity\CharacterFeatureAssignment;
use App\Entity\CharacterFeatureDefinition;
use App\Security\Voter\CampaignVoter;
use App\Service\ReferenceVisibility;
use App\Service\CharacterFeatureSpellService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/reference/features/{featureId}/assignments', requirements: ['featureId' => '\d+'])]
final class CharacterFeatureAssignmentController extends AbstractController
{
    #[Route('', methods: ['GET'])]
    public function list(int $featureId, EntityManagerInterface $em): JsonResponse
    {
        $feature = $this->feature($featureId, $em);
        $owner = $this->getUser();
        $assignments = $em->getRepository(CharacterFeatureAssignment::class)->findBy(['featureDefinition' => $feature]);
        $result = [];
        foreach ($assignments as $assignment) {
            $character = $assignment->getCharacter();
            if ($character->getCampaign()->getOwner()->getId() !== $owner->getId()) continue;
            $result[] = ['characterId' => $character->getId(), 'characterName' => $character->getName(), 'campaignId' => $character->getCampaign()->getId()];
        }
        return $this->json(['assignments' => $result]);
    }

    #[Route('', methods: ['POST'])]
    public function assign(int $featureId, Request $request, EntityManagerInterface $em, CharacterFeatureSpellService $spells): JsonResponse
    {
        $feature = $this->feature($featureId, $em);
        $payload = $request->toArray();
        $characterId = filter_var($payload['characterId'] ?? null, FILTER_VALIDATE_INT);
        if (!$characterId || $characterId < 1) return $this->json(['message' => 'Personnage invalide.'], 400);
        $character = $em->find(Character::class, $characterId);
        if (!$character) throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(CampaignVoter::MANAGE, $character->getCampaign());
        $repository = $em->getRepository(CharacterFeatureAssignment::class);
        if ($repository->findOneBy(['character' => $character, 'featureDefinition' => $feature])) {
            return $this->json(['message' => 'Capacité déjà attribuée.'], 409);
        }
        $em->persist(new CharacterFeatureAssignment($character, $feature));
        $em->flush();
        $spells->synchronize($character);
        return $this->json(['characterId' => $character->getId(), 'featureId' => $feature->getId()], 201);
    }

    #[Route('/{characterId}', requirements: ['characterId' => '\d+'], methods: ['DELETE'])]
    public function remove(int $featureId, int $characterId, EntityManagerInterface $em, CharacterFeatureSpellService $spells): JsonResponse
    {
        $feature = $this->feature($featureId, $em);
        $character = $em->find(Character::class, $characterId);
        if (!$character) throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(CampaignVoter::MANAGE, $character->getCampaign());
        $assignment = $em->getRepository(CharacterFeatureAssignment::class)->findOneBy(['character' => $character, 'featureDefinition' => $feature]);
        if (!$assignment) throw $this->createNotFoundException();
        $em->remove($assignment);
        $em->flush();
        $spells->synchronize($character);
        return new JsonResponse(null, 204);
    }

    private function feature(int $featureId, EntityManagerInterface $em): CharacterFeatureDefinition
    {
        $feature = $em->find(CharacterFeatureDefinition::class, $featureId);
        if (!$feature || !($owner = $this->getUser()) instanceof \App\Entity\User || !ReferenceVisibility::allows($feature, $owner)) {
            throw $this->createNotFoundException();
        }
        return $feature;
    }
}

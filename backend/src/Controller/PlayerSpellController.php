<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\{CharacterFeatureSpellService, CharacterSpellPreparation, PlayerCharacterAccess, PlayerSpellManagement};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/characters/{accessToken}/spells', requirements: ['accessToken'=>'[a-f0-9]{64}'])]
final class PlayerSpellController extends AbstractController
{
    public function __construct(
        private readonly PlayerCharacterAccess $access,
        private readonly EntityManagerInterface $em,
        private readonly PlayerSpellManagement $management,
        private readonly CharacterFeatureSpellService $features,
        private readonly CharacterSpellPreparation $preparation,
    ) {}

    #[Route('', name: 'api_public_character_spells', methods: ['GET'])]
    public function show(string $accessToken): JsonResponse
    {
        return $this->json($this->management->view($this->access->requireParticipating($accessToken)));
    }

    #[Route('/{operation}', name: 'api_public_character_spells_update', requirements: ['operation'=>'learn|choose|prepare'], methods: ['POST'])]
    public function update(string $accessToken, string $operation, Request $request): JsonResponse
    {
        $session = $this->access->requireParticipating($accessToken);
        if ($request->getContentTypeFormat() !== 'json' || !str_starts_with(ltrim($request->getContent()), '{')) throw new BadRequestHttpException('Objet JSON requis.');
        $data = $request->toArray();
        $keys = match ($operation) {
            'learn'=>['revision','policyId','spellId','ruleId'], 'choose'=>['revision','grantId','spellId'], 'prepare'=>['revision','policyId','spellIds'],
        };
        if (array_diff(array_keys($data), $keys) || array_diff($keys, array_keys($data))) throw new BadRequestHttpException('Champs invalides.');
        foreach (['revision', $operation === 'choose' ? 'grantId' : 'policyId', ...($operation === 'prepare' ? [] : ['spellId'])] as $key) {
            if (!is_int($data[$key]) || $data[$key] < 1) throw new BadRequestHttpException('Identifiant ou révision invalide.');
        }
        if ($operation === 'learn' && $data['ruleId'] !== null && (!is_int($data['ruleId']) || $data['ruleId'] < 1)) throw new BadRequestHttpException('Règle invalide.');
        if ($operation === 'prepare' && (!is_array($data['spellIds']) || count($data['spellIds']) > 100)) throw new BadRequestHttpException('Liste invalide.');
        return $this->em->getConnection()->transactional(function () use ($session, $operation, $data): JsonResponse {
            PlayerCharacterAccess::lockForMutation($this->em, $session);
            if ($session->getRevision() !== $data['revision']) throw new ConflictHttpException('Le personnage a changé. Rechargez ses sorts.');
            $character = $session->getCharacter();
            if ($operation === 'learn') $this->management->learn($character, $data['policyId'], $data['spellId'], $data['ruleId']);
            if ($operation === 'choose') $this->features->choose($character, $data['grantId'], $data['spellId'], $character->getCampaign()->getOwner()->getId());
            $state = $session->getState();
            if ($operation === 'prepare') $state = $this->preparation->update($character, $data['policyId'], $data['spellIds'], $state);
            $session->setState($state);
            $this->em->flush();
            return $this->json($this->management->view($session));
        });
    }
}

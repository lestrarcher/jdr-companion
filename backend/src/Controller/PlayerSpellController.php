<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\{CharacterFeatureSpellService, CharacterSpellPreparation, PlayerCharacterAccess, PlayerSpellManagement};
use App\Service\{CharacterSessionStateSerializer, CharacterSpellCastExecutor, SpellCastReceipts};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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

    #[Route('/cast', name: 'api_public_character_spells_cast', methods: ['POST'])]
    public function cast(
        string $accessToken,
        Request $request,
        CharacterSpellCastExecutor $executor,
        CharacterSessionStateSerializer $serializer,
    ): JsonResponse {
        if ($request->getContentTypeFormat() !== 'json' || !str_starts_with(ltrim($request->getContent()), '{')) {
            return $this->json(['message'=>'Objet JSON requis.'], 400);
        }
        try {
            $command = SpellCastReceipts::normalizeCommand($request->toArray());
        } catch (\Symfony\Component\HttpFoundation\Exception\JsonException | \InvalidArgumentException) {
            return $this->json(['message'=>'Commande de lancement invalide.'], 400);
        }
        try {
            $session = $this->access->requireParticipating($accessToken);
            // The executor owns its transaction and returns only after commit.
            $result = $executor->execute($session, $command);
        } catch (HttpExceptionInterface $error) {
            return $this->json(['message'=>$error->getMessage()], $error->getStatusCode());
        }
        // Never return the executor's internal state or its persisted receipts.
        return $this->json([
            ...$serializer->serialize($session),
            'cast'=>$result['cast'],
            'replayed'=>$result['replayed'],
        ]);
    }

    #[Route('/historical', name: 'api_public_character_spells_historical', methods: ['GET'])]
    public function historical(string $accessToken): JsonResponse
    {
        return $this->json($this->management->historicalView($this->access->requireParticipating($accessToken)));
    }

    #[Route('/{operation}', name: 'api_public_character_spells_update', requirements: ['operation'=>'learn|choose|prepare|confirm-preparation|historical-completion'], methods: ['POST'])]
    public function update(string $accessToken, string $operation, Request $request): JsonResponse
    {
        $session = $this->access->requireParticipating($accessToken);
        if ($request->getContentTypeFormat() !== 'json' || !str_starts_with(ltrim($request->getContent()), '{')) throw new BadRequestHttpException('Objet JSON requis.');
        $data = $request->toArray();
        $keys = match ($operation) {
            'historical-completion'=>['revision','policyId','choices','expectedAcquisitionIds','confirmed'],
            'learn'=>['revision','policyId','spellId','ruleId'], 'choose'=>['revision','grantId','spellId'], 'prepare'=>['revision','policyId','spellIds'],
            'confirm-preparation'=>['revision','selections'],
        };
        if (array_diff(array_keys($data), $keys) || array_diff($keys, array_keys($data))) throw new BadRequestHttpException('Champs invalides.');
        $identifiers = $operation === 'confirm-preparation' ? ['revision']
            : ['revision', $operation === 'choose' ? 'grantId' : 'policyId', ...(in_array($operation, ['prepare','historical-completion'], true) ? [] : ['spellId'])];
        foreach ($identifiers as $key) {
            if (!is_int($data[$key]) || $data[$key] < 1) throw new BadRequestHttpException('Identifiant ou révision invalide.');
        }
        if ($operation === 'learn' && $data['ruleId'] !== null && (!is_int($data['ruleId']) || $data['ruleId'] < 1)) throw new BadRequestHttpException('Règle invalide.');
        if ($operation === 'prepare' && (!is_array($data['spellIds']) || count($data['spellIds']) > 100)) throw new BadRequestHttpException('Liste invalide.');
        if ($operation === 'confirm-preparation' && (!is_array($data['selections']) || count($data['selections']) > 20)) throw new BadRequestHttpException('Liste de sélections invalide.');
        return $this->em->getConnection()->transactional(function () use ($session, $operation, $data): JsonResponse {
            PlayerCharacterAccess::lockForMutation($this->em, $session);
            if ($session->getRevision() !== $data['revision']) throw new ConflictHttpException('Le personnage a changé. Rechargez ses sorts.');
            $character = $session->getCharacter();
            if ($operation === 'historical-completion') {
                $result = $this->management->completeHistorical($character, $data['policyId'], array_intersect_key($data, array_flip(['choices','expectedAcquisitionIds','confirmed'])));
                if ($result['createdAcquisitionIds'] === []) return $this->json($this->management->historicalView($session));
            }
            if ($operation === 'learn') $this->management->learn($character, $data['policyId'], $data['spellId'], $data['ruleId']);
            if ($operation === 'choose') $this->features->choose($character, $data['grantId'], $data['spellId'], $character->getCampaign()->getOwner()->getId());
            $state = $session->getState();
            if ($operation === 'prepare') $state = $this->preparation->update($character, $data['policyId'], $data['spellIds'], $state);
            if ($operation === 'confirm-preparation') $state = $this->preparation->confirm($character, $data['selections'], $state);
            $session->setState($state);
            $this->em->flush();
            return $this->json($operation === 'historical-completion' ? $this->management->historicalView($session) : $this->management->view($session));
        });
    }
}

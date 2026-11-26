<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PlayerCharacterAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

final class PublicDivineSmiteController extends AbstractController
{
    #[Route('/public/characters/{accessToken}/divine-smite', requirements: ['accessToken' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function spend(string $accessToken, Request $request, PlayerCharacterAccess $access, EntityManagerInterface $em): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $id = is_array($payload) ? ($payload['resourceId'] ?? null) : null;
        if (!is_string($id) || !preg_match('/^(spell-slot-[1-9]|warlock-pact-slots)$/D', $id)) {
            return $this->json(['message' => 'Emplacement invalide.'], 400);
        }
        $session = $access->requireParticipating($accessToken);
        try {
            $em->wrapInTransaction(function () use ($em, $session, $id): void {
                PlayerCharacterAccess::lockForMutation($em, $session);
                $level = 0;
                foreach ($session->getCharacter()->getClassLevels() as $classLevel) {
                    if (mb_strtolower($classLevel->getCharacterClass()->getName()) === 'paladin') ++$level;
                }
                if ($level < 2) throw new \DomainException('Châtiment divin réservé aux paladins de niveau 2 ou plus.');
                $state = $session->getState();
                $indices = [];
                foreach ($state['resources'] ?? [] as $index => $resource) {
                    if (is_array($resource) && ($resource['id'] ?? null) === $id) $indices[] = $index;
                }
                if (count($indices) !== 1) throw new \DomainException('Emplacement indisponible.');
                $index = $indices[0];
                $remaining = $state['resources'][$index]['currentValue'] ?? null;
                if (!is_int($remaining) || $remaining < 1) throw new \DomainException('Aucun emplacement restant.');
                $state['resources'][$index]['currentValue'] = $remaining - 1;
                $session->setState($state);
                $em->flush();
            });
        } catch (\DomainException $e) {
            return $this->json(['message' => $e->getMessage()], 422);
        }
        return $this->json(['revision' => $session->getRevision(), 'resources' => $session->getState()['resources'] ?? []]);
    }
}

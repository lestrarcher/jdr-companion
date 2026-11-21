<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\{PlayerCharacterAccess, WildShapeSessionService, WildShapeRules2014, BeastNaturalWeaponCatalogue};
use Doctrine\DBAL\Connection;
use App\Repository\BeastRepository;
use App\Service\BeastSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/characters/{accessToken}/wild-shape', requirements: ['accessToken' => '[a-f0-9]{64}'])]
final class PublicWildShapeController extends AbstractController
{
    public function __construct(
        private readonly PlayerCharacterAccess $access,
        private readonly WildShapeSessionService $wildShape,
        private readonly WildShapeRules2014 $rules,
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly BeastNaturalWeaponCatalogue $naturalWeapons,
        private readonly BeastRepository $beasts,
        private readonly BeastSerializer $beastSerializer,
    ) {}

    #[Route('', methods: ['GET'])]
    public function get(string $accessToken): JsonResponse
    {
        $session = $this->access->requireParticipating($accessToken);
        $wildShape = $session->getState()[WildShapeSessionService::KEY] ?? ['usesRemaining' => 2, 'active' => null];
        // Les attaques viennent de la table dédiée, et non des textes du stat_block.
        // Ne pas persister ces données de catalogue dans l'état de session.
        if (is_array($wildShape['active'] ?? null) && !empty($wildShape['active']['beastId'])) {
            $wildShape['active']['naturalWeapons'] = $this->naturalWeapons->forBeast((int) $wildShape['active']['beastId']);
        }
        return $this->json(['wildShape' => $wildShape]);
    }

    #[Route('/features', methods: ['GET'])]
    public function features(string $accessToken): JsonResponse
    {
        $session = $this->access->requireParticipating($accessToken);
        $active = $session->getState()[WildShapeSessionService::KEY]['active'] ?? null;
        if (!is_array($active) || empty($active['beastId'])) {
            return $this->json(['beastId' => null, 'features' => []]);
        }
        $beastId = (int) $active['beastId'];
        $features = [];
        foreach ($this->beasts->find($beastId)?->getFeatures() ?? [] as $link) {
            $feature = $link->getDefinition();
            if ($feature->isVisible()) $features[] = ['id' => $feature->getId(), 'name' => $feature->getName(), 'description' => $feature->getDescription()];
        }
        return $this->json(['beastId' => $beastId, 'beastName' => (string) ($active['beastName'] ?? 'Forme animale'), 'features' => $features]);
    }

    #[Route('/{action}', requirements: ['action' => 'transform|revert|damage|heal|advance-time|lunar-heal'], methods: ['POST'])]
    public function mutate(string $accessToken, string $action, Request $request): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['message' => 'JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }
        if (!is_array($payload)) return $this->json(['message' => 'Objet JSON attendu.'], 400);
        $session = $this->access->requireParticipating($accessToken);
        try {
            $this->em->wrapInTransaction(function () use ($session, $action, $payload): void {
                PlayerCharacterAccess::lockForMutation($this->em, $session);
                $character = $session->getCharacter();
                $profile = $this->wildShape->druidProfile($character);
                if ($profile['level'] < 2) throw new \DomainException('Forme sauvage réservée aux druides de niveau 2 ou plus.');
                $state = $session->getState();
                if ($action === 'transform') {
                    $id = $payload['beastId'] ?? null;
                    $equipment = $payload['equipment'] ?? 'merge';
                    $rolledHitPoints = $payload['rolledHitPoints'] ?? null;
                    if ($rolledHitPoints !== null && (!is_int($rolledHitPoints) || $rolledHitPoints < 1)) {
                        throw new \DomainException('Total de PV lancé invalide.');
                    }
                    if (!is_int($id) || $id < 1 || !is_string($equipment)) throw new \DomainException('Bête ou équipement invalide.');
                    $entity = $this->beasts->find($id, \Doctrine\DBAL\LockMode::PESSIMISTIC_READ);
                    if (!$entity) throw new \DomainException('Bête inconnue.');
                    $beast = $this->beastSerializer->catalogue($entity);
                    $seen = (bool) $this->db->fetchOne('SELECT 1 FROM character_seen_beast WHERE character_id = ? AND beast_id = ?', [$character->getId(), $id]);
                    $state = $this->wildShape->transform($state, $beast, $profile['level'], $profile['moon'], $seen, $equipment, $rolledHitPoints);
                } elseif ($action === 'revert') {
                    $state = $this->wildShape->revert($state);
                } elseif ($action === 'lunar-heal') {
                    $level = $payload['slotLevel'] ?? null;
                    $active = $state[WildShapeSessionService::KEY]['active'] ?? null;
                    if (!$profile['moon'] || !is_array($active) || empty($active['moonCircle'])) {
                        throw new \DomainException('Guérison lunaire réservée au druide du Cercle de la Lune transformé.');
                    }
                    if (!is_int($level) || $level < 1 || $level > 9) {
                        throw new \DomainException('Niveau d’emplacement invalide.');
                    }
                    $slug = 'spell-slot-'.$level;
                    $indices = [];
                    foreach ($state['resources'] ?? [] as $index => $resource) {
                        if (is_array($resource) && ($resource['id'] ?? null) === $slug) $indices[] = $index;
                    }
                    if (count($indices) !== 1) throw new \DomainException('Emplacement de sort indisponible.');
                    $index = $indices[0];
                    $remaining = $state['resources'][$index]['currentValue'] ?? null;
                    if (!is_int($remaining) || $remaining < 1) throw new \DomainException('Aucun emplacement de ce niveau restant.');
                    // La capacité consomme seulement un emplacement. Le joueur lance
                    // ses dés physiques et applique ensuite les soins via État.
                    $state['resources'][$index]['currentValue'] = $remaining - 1;
                } else {
                    $amount = $payload['amount'] ?? null;
                    if (!is_int($amount) || $amount < 0 || $amount > 100000) throw new \DomainException('Valeur entière positive requise.');
                    $state = match ($action) {
                        'damage' => $this->wildShape->damage($state, $amount),
                        'heal' => $this->wildShape->heal($state, $amount),
                        'advance-time' => $this->wildShape->advanceTime($state, $amount),
                    };
                }
                $session->setState($state);
                $this->em->flush();
            });
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        }
        return $this->json(['wildShape' => $session->getState()[WildShapeSessionService::KEY] ?? null, 'hitPoints' => $session->getState()['hitPoints'] ?? null, 'revision' => $session->getRevision(), 'resources' => $session->getState()['resources'] ?? []]);
    }
}

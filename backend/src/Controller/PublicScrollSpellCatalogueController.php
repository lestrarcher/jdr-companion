<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SpellDefinition;
use App\Service\PlayerCharacterAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

final class PublicScrollSpellCatalogueController extends AbstractController
{
    #[Route('/public/characters/{accessToken}/scroll-spells', name: 'api_public_scroll_spells', requirements: ['accessToken' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function list(string $accessToken, Request $request, PlayerCharacterAccess $access, EntityManagerInterface $em): JsonResponse
    {
        $access->requireParticipating($accessToken);
        $query = trim($request->query->getString('q'));
        if (mb_strlen($query) > 150) {
            return $this->json(['message' => 'Recherche trop longue.'], 422);
        }
        $qb = $em->createQueryBuilder()->select('s')->from(SpellDefinition::class, 's');
        if ($query !== '') {
            $qb->where('LOWER(s.name) LIKE :query')->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        $spells = $qb->orderBy('s.name', 'ASC')->setMaxResults(50)->getQuery()->getResult();
        return $this->json(['spells' => array_map(static fn (SpellDefinition $spell): array => [
            'id' => $spell->getId(), 'name' => $spell->getName(), 'level' => $spell->getLevel(),
        ], $spells)]);
    }
}

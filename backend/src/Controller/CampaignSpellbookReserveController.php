<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\Character;
use App\Entity\CharacterMagicItem;
use App\Entity\SpellDefinition;
use App\Service\PactTomeLibrary;
use App\Service\ItemCatalogue;
use App\Service\SpellbookLibrary;
use App\Security\Voter\CampaignVoter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CampaignSpellbookReserveController extends AbstractController
{
    #[Route('/campaigns/{campaignId}/spellbook-reserve', name: 'api_campaign_spellbook_reserve', requirements: ['campaignId' => '\d+'], methods: ['GET'])]
    public function list(int $campaignId, EntityManagerInterface $em, SpellbookLibrary $library, PactTomeLibrary $pact): JsonResponse
    {
        $campaign = $this->campaign($campaignId, $em);
        $books = $em->getRepository(CharacterMagicItem::class)->findBy(['campaign' => $campaign, 'character' => null]);
        $result = [];
        foreach ($books as $book) {
            if ($book->getMagicItem()?->getCategory() !== 'spellbook') { continue; }
            $spells = $library->contents($book);
            $pactSpells = $pact->entries($book);
            $result[] = ['id' => $book->getId(), 'name' => $book->getDisplayName(), 'spellCount' => count($spells) + count($pactSpells), 'spells' => array_merge($spells, $pactSpells), 'pactTome' => $pactSpells !== []];
        }
        return $this->json(['books' => $result]);
    }

    #[Route('/campaigns/{campaignId}/spellbook-reserve', name: 'api_campaign_spellbook_reserve_create', requirements: ['campaignId' => '\d+'], methods: ['POST'])]
    public function create(int $campaignId, Request $request, EntityManagerInterface $em, ItemCatalogue $catalogue, SpellbookLibrary $library, PactTomeLibrary $pact): JsonResponse
    {
        $campaign = $this->campaign($campaignId, $em);
        try { $data = $request->toArray(); } catch (\Throwable) {
            return $this->json(['message' => 'JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }
        $definitionId = $data['magicItemId'] ?? null;
        $spellIds = $data['spellIds'] ?? [];
        $pactCantripIds = $data['pactCantripIds'] ?? null;
        $pactRitualIds = $data['pactRitualIds'] ?? null;
        $isPact = $pactCantripIds !== null || $pactRitualIds !== null;
        if (!is_int($definitionId) || $definitionId < 1 || !is_array($spellIds) || !array_is_list($spellIds)
            || count($spellIds) > 100 || count(array_unique($spellIds)) !== count($spellIds)
            || count(array_filter($spellIds, static fn ($id) => is_int($id) && $id > 0)) !== count($spellIds)
            || array_diff(array_keys($data), ['magicItemId', 'spellIds', 'pactCantripIds', 'pactRitualIds'])) {
            return $this->json(['message' => 'Paramètres invalides.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($isPact && ($spellIds !== [] || !is_array($pactCantripIds) || !is_array($pactRitualIds)
            || !array_is_list($pactCantripIds) || !array_is_list($pactRitualIds)
            || count($pactCantripIds) !== 3 || count($pactRitualIds) !== 2
            || count(array_unique(array_merge($pactCantripIds, $pactRitualIds))) !== 5
            || count(array_filter(array_merge($pactCantripIds, $pactRitualIds), static fn($id): bool => is_int($id) && $id > 0)) !== 5)) {
            return $this->json(['message' => 'Le pacte requiert exactement 3 sorts mineurs et 2 rituels de niveau 1.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $pactCantrips = $isPact ? $em->getRepository(SpellDefinition::class)->findBy(['id' => $pactCantripIds]) : [];
        $pactRituals = $isPact ? $em->getRepository(SpellDefinition::class)->findBy(['id' => $pactRitualIds]) : [];
        if ($isPact && (count($pactCantrips) !== 3 || count($pactRituals) !== 2
            || count(array_filter($pactCantrips, static fn(SpellDefinition $s): bool => $s->getLevel() === 0)) !== 3
            || count(array_filter($pactRituals, static fn(SpellDefinition $s): bool => $s->getLevel() === 1 && $s->isRitual())) !== 2)) {
            return $this->json(['message' => 'Sort mineur ou rituel initial invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $definition = $catalogue->requireDefinition($campaign, $definitionId, false);
        if ($definition->getCategory() !== 'spellbook' || $definition->getQuantityMode() !== 'individual') {
            return $this->json(['message' => 'Choisissez une définition de grimoire individuel.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $spells = $em->getRepository(SpellDefinition::class)->findBy(['id' => $spellIds]);
        if (count($spells) !== count($spellIds) || count(array_filter($spells, static fn (SpellDefinition $s) => $s->getLevel() > 0)) !== count($spells)) {
            return $this->json(['message' => 'Sort invalide ou tour de magie.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $book = $em->wrapInTransaction(function () use ($em, $campaign, $definition, $spells, $library, $pact, $isPact, $pactCantrips, $pactRituals) {
            $em->lock($campaign, LockMode::PESSIMISTIC_WRITE);
            $book = new CharacterMagicItem($campaign, $definition);
            $em->persist($book);
            foreach ($spells as $spell) { $library->inscribe($book, $spell); }
            if ($isPact) {
                $book->setDisplayName('Grimoire des Ombres');
                foreach ($pactCantrips as $spell) { $pact->inscribe($book, $spell, 'cantrip'); }
                foreach ($pactRituals as $spell) { $pact->inscribe($book, $spell, 'ritual'); }
            }
            $em->flush();
            return $book;
        });
        return $this->json(['id' => $book->getId(), 'name' => $book->getDisplayName(), 'spellCount' => count($spells) + ($isPact ? 5 : 0)], Response::HTTP_CREATED);
    }

    #[Route('/campaigns/{campaignId}/spellbook-reserve/{bookId}/assign', name: 'api_campaign_spellbook_reserve_assign', requirements: ['campaignId' => '\d+', 'bookId' => '\d+'], methods: ['POST'])]
    public function assign(int $campaignId, int $bookId, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $campaign = $this->campaign($campaignId, $em);
        try { $data = $request->toArray(); } catch (\Throwable) {
            return $this->json(['message' => 'JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }
        if (!is_int($data['characterId'] ?? null) || count($data) !== 1) {
            return $this->json(['message' => 'Personnage destinataire invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $character = $em->getRepository(Character::class)->find($data['characterId']);
        if (!$character instanceof Character || $character->getCampaign()->getId() !== $campaignId) {
            return $this->json(['message' => 'Personnage introuvable dans cette campagne.'], Response::HTTP_NOT_FOUND);
        }
        try {
            $em->wrapInTransaction(function () use ($em, $campaign, $bookId, $character): void {
                $book = $em->find(CharacterMagicItem::class, $bookId, LockMode::PESSIMISTIC_WRITE);
                if (!$book instanceof CharacterMagicItem || $book->getCampaign()->getId() !== $campaign->getId()
                    || $book->getMagicItem()?->getCategory() !== 'spellbook' || $book->getCharacter() !== null) {
                    throw new \DomainException('Ce grimoire n’est plus disponible dans la réserve.');
                }
                $em->lock($character, LockMode::PESSIMISTIC_WRITE);
                $book->transferTo($character);
                $character->addMagicItem($book);
            });
        } catch (\DomainException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        return $this->json(['id' => $bookId, 'characterId' => $character->getId()]);
    }

    private function campaign(int $id, EntityManagerInterface $em): Campaign
    {
        $campaign = $em->getRepository(Campaign::class)->find($id);
        if (!$campaign instanceof Campaign) { throw $this->createNotFoundException('Campagne introuvable.'); }
        $this->denyAccessUnlessGranted(CampaignVoter::MANAGE, $campaign);
        return $campaign;
    }
}

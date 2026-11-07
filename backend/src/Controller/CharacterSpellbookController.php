<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Character;
use App\Entity\CharacterMagicItem;
use App\Entity\SpellDefinition;
use App\Repository\CharacterRepository;
use App\Security\Voter\CampaignVoter;
use App\Service\SpellbookLibrary;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** GM-only physical spellbook contents management. */
final class CharacterSpellbookController extends AbstractController
{
    #[Route('/characters/{characterId}/spellbooks/{ownedItemId}/spells', name: 'api_character_spellbook_contents', requirements: ['characterId' => '\\d+', 'ownedItemId' => '\\d+'], methods: ['GET'])]
    public function contents(int $characterId, int $ownedItemId, CharacterRepository $characters, EntityManagerInterface $em, SpellbookLibrary $library): JsonResponse
    {
        $book = $this->ownedBook($characterId, $ownedItemId, $characters, $em);
        return $this->json(['ownedItemId' => $book->getId(), 'spells' => $library->contents($book)]);
    }

    #[Route('/characters/{characterId}/spellbooks/{ownedItemId}/spells', name: 'api_character_spellbook_inscribe', requirements: ['characterId' => '\\d+', 'ownedItemId' => '\\d+'], methods: ['POST'])]
    public function inscribe(int $characterId, int $ownedItemId, Request $request, CharacterRepository $characters, EntityManagerInterface $em, SpellbookLibrary $library): JsonResponse
    {
        $book = $this->ownedBook($characterId, $ownedItemId, $characters, $em);
        try {
            $payload = $request->toArray();
        } catch (\Symfony\Component\HttpFoundation\Exception\JsonException) {
            return $this->json(['message' => 'JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }
        $ids = $payload['spellIds'] ?? null;
        if (!is_array($ids) || $ids === [] || count($ids) > 100 || array_values($ids) !== $ids || count(array_filter($ids, static fn ($id) => is_int($id) && $id > 0)) !== count($ids) || count(array_unique($ids)) !== count($ids)) {
            return $this->json(['message' => 'spellIds doit être une liste de 1 à 100 identifiants distincts.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $spells = $em->getRepository(SpellDefinition::class)->findBy(['id' => $ids]);
        if (count($spells) !== count($ids) || count(array_filter($spells, static fn (SpellDefinition $spell) => $spell->getLevel() > 0)) !== count($spells)) {
            return $this->json(['message' => 'Sort inconnu ou sort mineur non autorisé.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $added = $em->wrapInTransaction(function () use ($em, $library, $book, $spells): int {
            $em->lock($book, LockMode::PESSIMISTIC_WRITE);
            $count = 0;
            foreach ($spells as $spell) {
                if ($library->inscribe($book, $spell)) {
                    ++$count;
                }
            }
            return $count;
        });
        return $this->json(['added' => $added, 'ownedItemId' => $book->getId(), 'spells' => $library->contents($book)]);
    }

    private function ownedBook(int $characterId, int $ownedItemId, CharacterRepository $characters, EntityManagerInterface $em): CharacterMagicItem
    {
        $character = $characters->find($characterId);
        if (!$character instanceof Character) {
            throw $this->createNotFoundException('Personnage introuvable.');
        }
        $this->denyAccessUnlessGranted(CampaignVoter::MANAGE, $character->getCampaign());
        $book = $em->getRepository(CharacterMagicItem::class)->find($ownedItemId);
        if (!$book instanceof CharacterMagicItem || $book->getCharacter()->getId() !== $character->getId() || $book->getMagicItem()?->getCategory() !== 'spellbook' || $book->getQuantity() !== 1) {
            throw $this->createNotFoundException('Grimoire introuvable.');
        }
        return $book;
    }
}

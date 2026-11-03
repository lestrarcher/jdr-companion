<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Character;
use App\Entity\SpellDefinition;
use App\Repository\CharacterRepository;
use App\Security\Voter\CampaignVoter;
use App\Service\CharacterInventory;
use App\Service\ItemCatalogue;
use App\Service\SpellbookLibrary;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Create one physical spellbook and inscribe its initial contents atomically. */
final class CreateCharacterSpellbookController extends AbstractController
{
    #[Route('/characters/{characterId}/spellbooks', name: 'api_character_spellbook_create', requirements: ['characterId' => '\\d+'], methods: ['POST'])]
    public function __invoke(
        int $characterId,
        Request $request,
        CharacterRepository $characters,
        EntityManagerInterface $em,
        ItemCatalogue $catalogue,
        CharacterInventory $inventory,
        SpellbookLibrary $library,
    ): JsonResponse {
        $character = $characters->find($characterId);
        if (!$character instanceof Character) {
            throw $this->createNotFoundException('Personnage introuvable.');
        }
        $this->denyAccessUnlessGranted(CampaignVoter::MANAGE, $character->getCampaign());

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['message' => 'JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }
        $itemId = $data['magicItemId'] ?? null;
        $ids = $data['spellIds'] ?? [];
        $notes = $data['notes'] ?? null;
        if (!is_int($itemId) || $itemId <= 0 || !is_array($ids) || count($ids) > 100
            || array_values($ids) !== $ids || count(array_filter($ids, static fn ($id) => is_int($id) && $id > 0)) !== count($ids)
            || count(array_unique($ids)) !== count($ids) || ($notes !== null && (!is_string($notes) || mb_strlen($notes) > 10000))) {
            return $this->json(['message' => 'Paramètres du grimoire invalides.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $definition = $catalogue->requireDefinition($character->getCampaign(), $itemId, false);
            if ($definition->getCategory() !== 'spellbook' || $definition->getQuantityMode() === 'stackable') {
                throw new \InvalidArgumentException('La définition doit être un grimoire individuel.');
            }
            $spells = $em->getRepository(SpellDefinition::class)->findBy(['id' => $ids]);
            if (count($spells) !== count($ids) || count(array_filter($spells, static fn (SpellDefinition $s) => $s->getLevel() > 0)) !== count($spells)) {
                throw new \InvalidArgumentException('Sort introuvable ou sort mineur non autorisé.');
            }
            $book = $em->wrapInTransaction(function () use ($em, $character, $inventory, $library, $itemId, $notes, $spells) {
                $em->lock($character, LockMode::PESSIMISTIC_WRITE);
                $owned = $inventory->acquire($character, $itemId, 1, false, $notes);
                $book = $owned[0];
                $book->setDisplayName('Grimoire de ' . $character->getName());
                foreach ($spells as $spell) {
                    $library->inscribe($book, $spell);
                }
                $em->flush();
                return $book;
            });
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'ownedItemId' => $book->getId(),
            'magicItemId' => $itemId,
            'spells' => $library->contents($book),
        ], Response::HTTP_CREATED);
    }
}

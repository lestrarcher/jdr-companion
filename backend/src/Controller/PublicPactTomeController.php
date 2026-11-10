<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\{CharacterMagicItem, SpellDefinition};
use App\Service\{PactInvocationAccess, PactTomeLibrary, PlayerCharacterAccess};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class PublicPactTomeController extends AbstractController
{
    #[Route('/public/characters/{accessToken}/pact-tomes/{bookId}/initial-spells', requirements: ['accessToken'=>'[a-f0-9]{64}', 'bookId'=>'\d+'], methods: ['POST'])]
    public function initialSpells(string $accessToken, int $bookId, Request $request, PlayerCharacterAccess $access,
        EntityManagerInterface $em, PactTomeLibrary $tomes, PactInvocationAccess $invocations): JsonResponse
    {
        $session = $access->requireParticipating($accessToken);
        try { $data = $request->toArray(); } catch (\Throwable) {
            return $this->json(['message'=>'JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }
        $cantrips = $data['cantripIds'] ?? null;
        $rituals = $data['ritualIds'] ?? null;
        if (!is_array($cantrips) || !is_array($rituals) || !array_is_list($cantrips) || !array_is_list($rituals)
            || array_diff(array_keys($data), ['cantripIds','ritualIds'])
            || count($cantrips) !== 3 || !in_array(count($rituals), [0, 2], true)
            || count(array_unique(array_merge($cantrips,$rituals))) !== 5
            || count(array_filter(array_merge($cantrips,$rituals), static fn($id) => is_int($id) && $id > 0)) !== 5) {
            return $this->json(['message'=>'Sélection requise : 3 mineurs et, facultativement, 2 rituels distincts.'], 422);
        }
        try {
            $em->wrapInTransaction(function () use ($em,$session,$bookId,$tomes,$invocations,$cantrips,$rituals): void {
                PlayerCharacterAccess::lockForMutation($em, $session);
                $character = $session->getCharacter();
                $book = $em->find(CharacterMagicItem::class, $bookId, LockMode::PESSIMISTIC_WRITE);
                if (!$book || $book->getCharacter()?->getId() !== $character->getId()
                    || $book->getPactOwner()?->getId() !== $character->getId()
                    || $tomes->warlockLevel($character) < 3) throw new \DomainException('Livre des ombres personnel requis.');
                if ($rituals !== [] && !$invocations->hasAncientSecrets($character)) throw new \DomainException('Livre des secrets anciens requis pour les rituels.');
                if ($tomes->entries($book) !== []) throw new \DomainException('Ce livre contient déjà des inscriptions.');
                foreach ([['ids'=>$cantrips,'kind'=>'cantrip'],['ids'=>$rituals,'kind'=>'ritual']] as $group) {
                    foreach ($group['ids'] as $id) {
                        $spell = $em->find(SpellDefinition::class,$id);
                        if (!$spell || ($group['kind'] === 'cantrip' ? $spell->getLevel() !== 0 : $spell->getLevel() !== 1 || !$spell->isRitual())) {
                            throw new \DomainException('Sort initial invalide.');
                        }
                        $tomes->inscribe($book,$spell,$group['kind']);
                    }
                }
                $em->flush();
            });
        } catch (\DomainException $e) {
            return $this->json(['message'=>$e->getMessage()], Response::HTTP_CONFLICT);
        }
        return $this->json(['success'=>true]);
    }
}

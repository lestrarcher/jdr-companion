<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Character;
use App\Entity\CharacterMagicItem;
use App\Entity\MagicItem;
use App\Repository\CharacterRepository;
use App\Security\Voter\CampaignVoter;
use App\Service\CharacterAbilityCalculator;
use App\Service\CharacterInventory;
use App\Service\MagicItemRechargeService;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Doctrine\DBAL\LockMode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CharacterMagicItemController
    extends AbstractController
{
    #[Route('/characters/{characterId}/magic-items/{ownedItemId}/equipment', name: 'api_character_item_equipment', requirements: ['characterId' => '\d+', 'ownedItemId' => '\d+'], methods: ['PATCH'])]
    public function equip(int $characterId, int $ownedItemId, Request $request, CharacterRepository $characters, EntityManagerInterface $em, \App\Service\CharacterEquipmentService $equipment, CharacterAbilityCalculator $abilities): JsonResponse
    {
        $character = $this->getOwnedCharacter($characterId, $characters);
        try { $payload = $request->toArray(); } catch (JsonException) {
            return $this->json(['message' => 'JSON invalide.'], 400);
        }
        if (array_keys($payload) !== ['equipped'] || !is_bool($payload['equipped'])) {
            return $this->json(['message' => 'Un booléen equipped est attendu.'], 422);
        }
        return $em->wrapInTransaction(function () use ($em, $character, $ownedItemId, $equipment, $payload, $abilities): JsonResponse {
            $em->lock($character, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($character);
            $owned = null;
            foreach ($character->getMagicItems() as $item) {
                $em->lock($item, LockMode::PESSIMISTIC_WRITE);
                $em->refresh($item);
                if ($item->getId() === $ownedItemId) $owned = $item;
            }
            if ($owned === null) throw $this->createNotFoundException('Objet possédé introuvable.');
            try { $equipment->equip($character, $owned, $payload['equipped']); }
            catch (\InvalidArgumentException $error) {
                throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException($error->getMessage(), $error);
            }
            $em->flush();
            return $this->serializeInventory($character, $abilities);
        });
    }

    #[Route('/characters/{characterId}/scroll-spells', name: 'api_character_scroll_spells', requirements: ['characterId' => '\d+'], methods: ['GET'])]
    public function scrollSpells(int $characterId, Request $request, CharacterRepository $characters, EntityManagerInterface $em): JsonResponse
    {
        $this->getOwnedCharacter($characterId, $characters);
        $query = trim($request->query->getString('q'));
        if (mb_strlen($query) > 150) {
            return $this->json(['message' => 'Recherche trop longue.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $qb = $em->createQueryBuilder()->select('s')->from(\App\Entity\SpellDefinition::class, 's');
        if ($query !== '') {
            $qb->where('LOWER(s.name) LIKE :q')->setParameter('q', '%'.mb_strtolower($query).'%');
        }
        $spells = $qb->orderBy('s.name', 'ASC')->setMaxResults(50)->getQuery()->getResult();
        return $this->json(['spells' => array_map(static fn (\App\Entity\SpellDefinition $spell): array => [
            'id' => $spell->getId(), 'name' => $spell->getName(), 'level' => $spell->getLevel(),
        ], $spells)]);
    }

    #[Route(
        '/characters/{characterId}/magic-items',
        name: 'api_character_magic_item_list',
        requirements: [
            'characterId' => '\d+',
        ],
        methods: ['GET'],
    )]
    public function list(
        int $characterId,
        CharacterRepository $characterRepository,
        CharacterAbilityCalculator $abilityCalculator,
    ): JsonResponse {
        $character = $this->getOwnedCharacter(
            $characterId,
            $characterRepository,
        );

        return $this->serializeInventory(
            $character,
            $abilityCalculator,
        );
    }

#[Route(
    '/characters/{characterId}/magic-items',
    name: 'api_character_magic_item_assign',
    requirements: ['characterId' => '\d+'],
    methods: ['POST'],
)]
public function assign(
    int $characterId,
    Request $request,
    CharacterRepository $characterRepository,
    EntityManagerInterface $entityManager,
    CharacterAbilityCalculator $abilityCalculator,
    CharacterInventory $inventory,
): JsonResponse {
    $character = $this->getOwnedCharacter($characterId, $characterRepository);

    try {
        $payload = $request->toArray();
    } catch (JsonException) {
        return $this->json(
            ['message' => 'Le corps JSON est invalide.'],
            Response::HTTP_BAD_REQUEST
        );
    }

    // Validation de l'identifiant de la définition.
    $magicItemId = filter_var($payload['magicItemId'] ?? null, FILTER_VALIDATE_INT);

    if ($magicItemId === false || $magicItemId === null || $magicItemId <= 0) {
        return $this->json(
            ['message' => 'L’identifiant de l’objet est invalide.'],
            Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }

    try {
        $quantity = $payload['quantity'] ?? 1;

        if (!is_int($quantity)) {
            throw new \InvalidArgumentException('La quantité doit être un entier.');
        }

        $notes = $payload['notes'] ?? null;
        $inscribedSpellId = $payload['inscribedSpellId'] ?? null;

        if ($notes !== null && !is_string($notes)) {
            throw new \InvalidArgumentException('Les notes doivent être du texte.');
        }

        if ($inscribedSpellId !== null && (!is_int($inscribedSpellId) || $inscribedSpellId < 1)) {
            throw new \InvalidArgumentException('Identifiant de sort invalide.');
        }

        $ownedItems = $entityManager->wrapInTransaction(function () use (
            $entityManager,
            $inventory,
            $character,
            $magicItemId,
            $quantity,
            $notes,
            $inscribedSpellId
        ): array {
            $entityManager->lock($character, LockMode::PESSIMISTIC_WRITE);
            $entityManager->refresh($character);

            return $inventory->acquire(
                $character,
                $magicItemId,
                $quantity,
                false,
                $notes,
                $inscribedSpellId
            );
        });
    } catch (\InvalidArgumentException $exception) {
        return $this->json(
            ['message' => $exception->getMessage()],
            Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }

    return $this->json([
        'ownedItem' => $this->serializeOwnedItem($ownedItems[0]),
        'ownedItems' => array_map(
            fn (CharacterMagicItem $item) => $this->serializeOwnedItem($item),
            $ownedItems
        ),
        'abilities' => $this->serializeAbilities($character, $abilityCalculator),
    ], Response::HTTP_CREATED);
}


    #[Route(
        '/characters/{characterId}/magic-items/{ownedItemId}',
        name: 'api_character_magic_item_remove',
        requirements: [
            'characterId' => '\d+',
            'ownedItemId' => '\d+',
        ],
        methods: ['DELETE'],
    )]
    public function remove(
        int $characterId,
        int $ownedItemId,
        Request $request,
        CharacterRepository $characterRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $character = $this->getOwnedCharacter($characterId, $characterRepository);
        $quantityRaw = $request->query->get('quantity', '1');
        $quantity = filter_var($quantityRaw, FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 1 || $quantity > 9999) {
            return $this->json(['message' => 'Quantité invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $entityManager->wrapInTransaction(function () use ($entityManager, $character, $ownedItemId, $quantity): void {
                $entityManager->lock($character, LockMode::PESSIMISTIC_WRITE);
                $ownedItem = $entityManager->getRepository(CharacterMagicItem::class)->find($ownedItemId);
                if (!$ownedItem instanceof CharacterMagicItem || $ownedItem->getCharacter()->getId() !== $character->getId()) {
                    throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Objet possédé introuvable.');
                }
                $entityManager->lock($ownedItem, LockMode::PESSIMISTIC_WRITE);
                if ($quantity > $ownedItem->getQuantity()) {
                    throw new \InvalidArgumentException('Quantité supérieure au stock possédé.');
                }
                if ($quantity === $ownedItem->getQuantity()) {
                    if ($ownedItem->getMagicItem()?->getCategory() === 'spellbook') {
                        $character->removeMagicItem($ownedItem);
                        $ownedItem->transferTo(null);
                    } else {
                        $character->removeMagicItem($ownedItem);
                        $entityManager->remove($ownedItem);
                    }
                } else {
                    $ownedItem->setQuantity($ownedItem->getQuantity() - $quantity);
                }
            });
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** Explicit GM action: dawn is never inferred from a long rest. */
    #[Route(
        '/characters/{characterId}/magic-items/recharge-dawn',
        name: 'api_character_magic_item_recharge_dawn',
        requirements: ['characterId' => '\d+'],
        methods: ['POST'],
    )]
    public function rechargeDawn(
        int $characterId,
        CharacterRepository $characterRepository,
        EntityManagerInterface $entityManager,
        MagicItemRechargeService $rechargeService,
        CharacterAbilityCalculator $abilityCalculator,
    ): JsonResponse {
        $character = $this->getOwnedCharacter($characterId, $characterRepository);
        $summary = $entityManager->wrapInTransaction(function () use ($entityManager, $character, $rechargeService): array {
            $entityManager->lock($character, LockMode::PESSIMISTIC_WRITE);
            foreach ($character->getMagicItems() as $owned) {
                $entityManager->lock($owned, LockMode::PESSIMISTIC_WRITE);
            }
            return $rechargeService->apply($character, 'dawn');
        });
        return $this->json(['recharged' => $summary, 'inventory' => json_decode(
            $this->serializeInventory($character, $abilityCalculator)->getContent(), true, 512, JSON_THROW_ON_ERROR
        )]);
    }

    private function getOwnedCharacter(
        int $characterId,
        CharacterRepository $characterRepository,
    ): Character {
        $character =
            $characterRepository->find(
                $characterId,
            );

        if (!$character instanceof Character) {
            throw $this
                ->createNotFoundException(
                    'Personnage introuvable.',
                );
        }

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $character->getCampaign(),
        );

        return $character;
    }

    private function nullableString(
        mixed $value,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim(
            (string) $value,
        );

        return $value !== ''
            ? $value
            : null;
    }

    private function serializeInventory(
        Character $character,
        CharacterAbilityCalculator $abilityCalculator,
    ): JsonResponse {
        return $this->json([
            'characterId' =>
                $character->getId(),

            'attunedCount' =>
                $character
                    ->getAttunedMagicItemCount(),

            'attunementLimit' => 3,

            'items' => array_map(
                fn (
                    CharacterMagicItem $item,
                ): array =>
                    $this->serializeOwnedItem(
                        $item,
                    ),
                $character
                    ->getMagicItems()
                    ->toArray(),
            ),

            'abilities' =>
                $this->serializeAbilities(
                    $character,
                    $abilityCalculator,
                ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOwnedItem(
        CharacterMagicItem $ownedItem,
    ): array {
        $item =
            $ownedItem->getMagicItem();

        return [
            'id' =>
                $ownedItem->getId(),
            'magicItemId' =>
                $item?->getId(),
            'name' => $ownedItem->getInscribedSpell() !== null ? 'Parchemin de ' . $ownedItem->getInscribedSpell()->getName() : $ownedItem->getDisplayName(),
            'inscribedSpellId' => $ownedItem->getInscribedSpell()?->getId(),
            'description' =>
                $item?->getDescription() ?? $ownedItem->getCustomDescription(),
            'rarity' =>
                $item?->getRarity()?->value,
            'category' => $item?->getCategory() ?? 'other',
            'equippable' => $item?->isEquippable() ?? false,
            'rarityLabel' =>
                $item?->getRarity()?->label(),
            'requiresAttunement' =>
                $item
                    ?->requiresAttunement() ?? false,
            'attuned' =>
                $ownedItem->isAttuned(),
            'equipped' =>
                $ownedItem->isEquipped(),
            'effectActive' =>
                $ownedItem
                    ->isEffectActive(),
            'quantity' =>
                $ownedItem->getQuantity(),
            'currentCharges' =>
                $ownedItem
                    ->getCurrentCharges(),
            'maximumCharges' =>
                $item
                    ?->getMaximumCharges(),
            'rechargeType' =>
                $item
                    ?->getRechargeType()
                    ->value ?? 'none',
            'rechargeLabel' =>
                $item
                    ?->getRechargeType()
                    ->label() ?? 'Aucune',
            'rechargeFormula' =>
                $item
                    ?->getRechargeFormula(),
            'notes' =>
                $ownedItem->getNotes(),
            'acquiredAt' =>
                $ownedItem
                    ->getAcquiredAt()
                    ->format(DATE_ATOM),
            'updatedAt' =>
                $ownedItem
                    ->getUpdatedAt()
                    ->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function serializeAbilities(
        Character $character,
        CharacterAbilityCalculator $abilityCalculator,
    ): array {
        return array_map(
            static fn ($ability): array =>
                $ability->toArray(),
            $abilityCalculator
                ->calculateAll($character),
        );
    }
}

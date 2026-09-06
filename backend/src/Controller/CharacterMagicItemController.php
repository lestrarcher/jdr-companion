<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Character;
use App\Entity\CharacterMagicItem;
use App\Entity\MagicItem;
use App\Repository\CharacterRepository;
use App\Security\Voter\CampaignVoter;
use App\Service\CharacterAbilityCalculator;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CharacterMagicItemController
    extends AbstractController
{
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
        requirements: [
            'characterId' => '\d+',
        ],
        methods: ['POST'],
    )]
    public function assign(
        int $characterId,
        Request $request,
        CharacterRepository $characterRepository,
        EntityManagerInterface $entityManager,
        CharacterAbilityCalculator $abilityCalculator,
    ): JsonResponse {
        $character = $this->getOwnedCharacter(
            $characterId,
            $characterRepository,
        );

        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            return $this->json(
                [
                    'message' =>
                        'Le corps JSON est invalide.',
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $magicItemId = filter_var(
            $payload['magicItemId'] ?? null,
            FILTER_VALIDATE_INT,
        );

        if (
            $magicItemId === false
            || $magicItemId <= 0
        ) {
            return $this->json(
                [
                    'message' =>
                        'L’identifiant de l’objet est invalide.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $magicItem = $entityManager
            ->getRepository(MagicItem::class)
            ->find($magicItemId);

        if (!$magicItem instanceof MagicItem) {
            return $this->json(
                [
                    'message' =>
                        'Objet magique introuvable.',
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        if (
            $magicItem
                ->getCampaign()
                ->getId()
            !== $character
                ->getCampaign()
                ->getId()
        ) {
            return $this->json(
                [
                    'message' =>
                        'Cet objet n’appartient pas à la campagne du personnage.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $ownedItem =
                new CharacterMagicItem(
                    character: $character,
                    magicItem: $magicItem,
                );

            if (
                array_key_exists(
                    'quantity',
                    $payload,
                )
            ) {
                $quantity = filter_var(
                    $payload['quantity'],
                    FILTER_VALIDATE_INT,
                );

                if ($quantity === false) {
                    throw new \InvalidArgumentException(
                        'La quantité doit être un entier.',
                    );
                }

                $ownedItem->setQuantity(
                    $quantity,
                );
            }

            $ownedItem->setNotes(
                $this->nullableString(
                    $payload['notes'] ?? null,
                ),
            );

            $character->addMagicItem(
                $ownedItem,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->json(
                [
                    'message' =>
                        $exception->getMessage(),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $entityManager->persist(
            $ownedItem,
        );

        $entityManager->flush();

        return $this->json(
            [
                'ownedItem' =>
                    $this->serializeOwnedItem(
                        $ownedItem,
                    ),
                'abilities' =>
                    $this->serializeAbilities(
                        $character,
                        $abilityCalculator,
                    ),
            ],
            Response::HTTP_CREATED,
        );
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
        CharacterRepository $characterRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $character = $this->getOwnedCharacter(
            $characterId,
            $characterRepository,
        );

        $ownedItem = $entityManager
            ->getRepository(
                CharacterMagicItem::class,
            )
            ->find($ownedItemId);

        if (
            !$ownedItem instanceof
                CharacterMagicItem
            || $ownedItem
                ->getCharacter()
                ->getId()
                !== $character->getId()
        ) {
            return $this->json(
                [
                    'message' =>
                        'Objet possédé introuvable.',
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        $character->removeMagicItem(
            $ownedItem,
        );

        $entityManager->remove(
            $ownedItem,
        );

        $entityManager->flush();

        return new JsonResponse(
            null,
            Response::HTTP_NO_CONTENT,
        );
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
                $item->getId(),
            'name' =>
                $item->getName(),
            'description' =>
                $item->getDescription(),
            'rarity' =>
                $item->getRarity()->value,
            'rarityLabel' =>
                $item
                    ->getRarity()
                    ->label(),
            'requiresAttunement' =>
                $item
                    ->requiresAttunement(),
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
                    ->getMaximumCharges(),
            'rechargeType' =>
                $item
                    ->getRechargeType()
                    ->value,
            'rechargeLabel' =>
                $item
                    ->getRechargeType()
                    ->label(),
            'rechargeFormula' =>
                $item
                    ->getRechargeFormula(),
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

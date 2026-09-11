<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PlayerCharacterAccess;

use App\Entity\Character;
use App\Entity\CharacterMagicItem;
use App\Entity\CharacterSessionState;
use App\Entity\GameSession;
use App\Repository\CharacterMagicItemRepository;
use App\Service\CharacterAbilityCalculator;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicCharacterMagicItemController extends AbstractController
{
    private const ATTUNEMENT_LIMIT = 3;

    #[Route(
        '/public/characters/{accessToken}/magic-items',
        name: 'api_public_character_magic_item_list',
        requirements: [
            'accessToken' => '[a-f0-9]{64}',
        ],
        methods: ['GET'],
    )]
    public function list(
        string $accessToken,
        PlayerCharacterAccess $playerAccess,
        CharacterAbilityCalculator $abilityCalculator,
    ): JsonResponse {
        $sessionState = $playerAccess->requireParticipating($accessToken);

        return $this->json(
            $this->serializeCharacterInventory(
                $sessionState->getCharacter(),
                $abilityCalculator,
            ),
        );
    }

    #[Route(
        '/public/characters/{accessToken}/magic-items/{ownedItemId}',
        name: 'api_public_character_magic_item_update',
        requirements: [
            'accessToken' => '[a-f0-9]{64}',
            'ownedItemId' => '\d+',
        ],
        methods: ['PATCH'],
    )]
    public function update(
        string $accessToken,
        int $ownedItemId,
        Request $request,
        PlayerCharacterAccess $playerAccess,
        CharacterMagicItemRepository $ownedItemRepository,
        CharacterAbilityCalculator $abilityCalculator,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $sessionState = $playerAccess->requireParticipating($accessToken);

        if (
            $sessionState->getGameSession()->getStatus()
            !== GameSession::STATUS_LIVE
        ) {
            return $this->json(
                ['message' => 'Cette session n’est pas ouverte.'],
                Response::HTTP_CONFLICT,
            );
        }

        return $entityManager->wrapInTransaction(function () use ($accessToken, $ownedItemId, $request, $playerAccess, $ownedItemRepository, $abilityCalculator, $entityManager, $sessionState): JsonResponse {
            PlayerCharacterAccess::lockForMutation($entityManager, $sessionState);

            $character = $sessionState->getCharacter();
            $ownedItem = $ownedItemRepository->find($ownedItemId);

            if (
                !$ownedItem instanceof CharacterMagicItem
                || $ownedItem->getCharacter()->getId() !== $character->getId()
            ) {
                return $this->json(
                    ['message' => 'Objet magique introuvable.'],
                    Response::HTTP_NOT_FOUND,
                );
            }

            try {
                $payload = $request->toArray();
            } catch (JsonException) {
                return $this->json(
                    ['message' => 'Le corps JSON est invalide.'],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            foreach ($character->getMagicItems() as $item) {
                $entityManager->refresh($item, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            }

            try {
                $this->applyUpdate(
                    $ownedItem,
                    $character,
                    $payload,
                );
            } catch (\InvalidArgumentException $exception) {
                throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException($exception->getMessage(), $exception);
            }

            $entityManager->flush();

            return $this->json([
                'ownedItem' => $this->serializeOwnedItem($ownedItem),
                ...$this->serializeCharacterInventory(
                    $character,
                    $abilityCalculator,
                ),
            ]);
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyUpdate(
        CharacterMagicItem $ownedItem,
        Character $character,
        array $payload,
    ): void {
        if (array_key_exists('equipped', $payload)) {
            if (!is_bool($payload['equipped'])) {
                throw new \InvalidArgumentException(
                    'La propriété "equipped" doit être un booléen.',
                );
            }

            $ownedItem->setEquipped($payload['equipped']);

            if (!$payload['equipped'] && $ownedItem->isAttuned()) {
                $ownedItem->setAttuned(false);
            }
        }

        if (array_key_exists('attuned', $payload)) {
            if (!is_bool($payload['attuned'])) {
                throw new \InvalidArgumentException(
                    'La propriété "attuned" doit être un booléen.',
                );
            }

            $shouldBeAttuned = $payload['attuned'];

            if (
                $shouldBeAttuned
                && !$ownedItem->isAttuned()
                && $character->getAttunedMagicItemCount()
                    >= self::ATTUNEMENT_LIMIT
            ) {
                throw new \InvalidArgumentException(
                    'Un personnage ne peut pas être harmonisé avec plus de trois objets.',
                );
            }

            if ($shouldBeAttuned && !$ownedItem->isEquipped()) {
                $ownedItem->setEquipped(true);
            }

            $ownedItem->setAttuned($shouldBeAttuned);
        }

        if (array_key_exists('chargeChange', $payload)) {
            $chargeChange = filter_var(
                $payload['chargeChange'],
                FILTER_VALIDATE_INT,
            );

            if ($chargeChange === false || $chargeChange === 0) {
                throw new \InvalidArgumentException(
                    'La variation de charges doit être un entier différent de zéro.',
                );
            }

            $maximumCharges = $ownedItem
                ->getMagicItem()
                ->getMaximumCharges();

            if ($maximumCharges === null) {
                throw new \InvalidArgumentException(
                    'Cet objet ne possède pas de charges.',
                );
            }

            $newCharges = $ownedItem->getCurrentCharges() + $chargeChange;

            if ($newCharges < 0 || $newCharges > $maximumCharges) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Le nombre de charges doit rester compris entre 0 et %d.',
                        $maximumCharges,
                    ),
                );
            }

            $ownedItem->setCurrentCharges($newCharges);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCharacterInventory(
        Character $character,
        CharacterAbilityCalculator $abilityCalculator,
    ): array {
        return [
            'characterId' => $character->getId(),
            'attunedCount' => $character->getAttunedMagicItemCount(),
            'attunementLimit' => self::ATTUNEMENT_LIMIT,
            'ownedItems' => array_map(
                fn (CharacterMagicItem $ownedItem): array =>
                    $this->serializeOwnedItem($ownedItem),
                $character->getMagicItems()->toArray(),
            ),
            'effectiveAbilities' => array_map(
                static fn ($abilityScore): array => $abilityScore->toArray(),
                $abilityCalculator->calculateAll($character),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOwnedItem(
        CharacterMagicItem $ownedItem,
    ): array {
        $magicItem = $ownedItem->getMagicItem();

        return [
            'id' => $ownedItem->getId(),
            'quantity' => $ownedItem->getQuantity(),
            'currentCharges' => $ownedItem->getCurrentCharges(),
            'attuned' => $ownedItem->isAttuned(),
            'equipped' => $ownedItem->isEquipped(),
            'effectActive' => $ownedItem->isEffectActive(),
            'notes' => $ownedItem->getNotes(),
            'magicItem' => [
                'id' => $magicItem->getId(),
                'name' => $magicItem->getName(),
                'description' => $magicItem->getDescription(),
                'rarity' => $magicItem->getRarity()->value,
                'requiresAttunement' =>
                    $magicItem->requiresAttunement(),
                'maximumCharges' =>
                    $magicItem->getMaximumCharges(),
                'rechargeType' =>
                    $magicItem->getRechargeType()->value,
                'rechargeFormula' =>
                    $magicItem->getRechargeFormula(),
                'abilityEffects' => array_map(
                    static fn ($effect): array => [
                        'ability' => $effect->getAbility()->value,
                        'operation' => $effect->getOperation()->value,
                        'value' => $effect->getValue(),
                        'scoreCap' => $effect->getScoreCap(),
                        'maximumIncrease' =>
                            $effect->getMaximumIncrease(),
                    ],
                    $magicItem->getAbilityEffects()->toArray(),
                ),
            ],
        ];
    }
}

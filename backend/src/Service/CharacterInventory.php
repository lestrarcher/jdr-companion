<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterMagicItem;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CharacterInventory
{
    public function __construct(
        private EntityManagerInterface $em,
        private ItemCatalogue $catalogue,
    ) {}


    public function acquire(
        Character $character,
        int $definitionId,
        int $quantity = 1,
        bool $player = false,
        ?string $notes = null,
    ): array {
        if ($quantity < 1 || $quantity > 9999) {
            throw new \InvalidArgumentException(
                'La quantité doit être comprise entre 1 et 9999.'
            );
        }

        $definition = $this->catalogue->requireDefinition(
            $character->getCampaign(),
            $definitionId,
            $player
        );

        // Regrouper les objets empilables déjà possédés.
        if ($definition->getQuantityMode() === 'stackable') {
            $existingItems = $this->em->getRepository(CharacterMagicItem::class)->findBy([
                'character' => $character,
                'magicItem' => $definition,
            ]);

            foreach ($existingItems as $owned) {
                if ($owned->isLegacyStack()) {
                    continue;
                }

                $newQuantity = $owned->getQuantity() + $quantity;

                if ($newQuantity > 9999) {
                    throw new \InvalidArgumentException('Quantité maximale dépassée.');
                }

                $owned->setQuantity($newQuantity);
                $this->em->flush();

                return [$owned];
            }
        }

        // Créer une pile ou plusieurs exemplaires individuels.
        $individual = $definition->getQuantityMode() !== 'stackable';
        $created = [];

        for ($i = 0; $i < ($individual ? $quantity : 1); $i++) {
            $owned = new CharacterMagicItem($character, $definition);

            if (!$individual) {
                $owned->setQuantity($quantity);
            }

            $owned->setNotes($notes);
            $character->addMagicItem($owned);
            $this->em->persist($owned);
            $created[] = $owned;
        }

        $this->em->flush();

        return $created;
    }

}

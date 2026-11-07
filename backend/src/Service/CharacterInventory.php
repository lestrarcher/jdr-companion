<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterMagicItem;
use App\Entity\SpellDefinition;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CharacterInventory
{
    public function __construct(
        private EntityManagerInterface $em,
        private ItemCatalogue $catalogue,
    ) {}

    public function createCustom(Character $character, string $name, ?string $description = null, int $quantity = 1): CharacterMagicItem
    {
        $owned = CharacterMagicItem::custom($character, $name, $description, $quantity);
        // The caller holds the character mutation lock, including for concurrent creations.
        $normalizeName = static fn (string $value): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value), 'UTF-8');
        foreach ($this->em->getRepository(CharacterMagicItem::class)->findBy(
            ['character' => $character, 'magicItem' => null], ['id' => 'ASC'],
        ) as $existing) {
            $this->em->refresh($existing);
            if (!$existing->isStackableCustom()
                || $normalizeName($existing->getDisplayName()) !== $normalizeName($owned->getDisplayName())
                || $existing->getCustomDescription() !== $owned->getCustomDescription()) {
                continue;
            }
            $existing->setQuantity($existing->getQuantity() + $quantity);
            $this->em->flush();

            return $existing;
        }
        $character->addMagicItem($owned);
        $this->em->persist($owned);
        $this->em->flush();

        return $owned;
    }

    public function acquire(
        Character $character,
        int $definitionId,
        int $quantity = 1,
        bool $player = false,
        ?string $notes = null,
        ?int $inscribedSpellId = null,
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

        // Seul le modèle officiel de parchemin accepte un sort variable.
        $isScroll = $definition->getImportKey() === 'srd-5.1:magic-item:spell-scroll';
        $inscribedSpell = null;
        if ($isScroll) {
            if ($player) {
                throw new \InvalidArgumentException('Un parchemin doit être préparé et attribué par le MJ.');
            }
            if ($player) {
                throw new \InvalidArgumentException('Un parchemin doit être préparé et attribué par le MJ.');
            }
            if ($player) {
                throw new \InvalidArgumentException('Un parchemin doit être préparé et attribué par le MJ.');
            }
            if ($inscribedSpellId === null || $inscribedSpellId < 1) {
                throw new \InvalidArgumentException('Choisissez le sort inscrit sur le parchemin.');
            }
            $inscribedSpell = $this->em->getRepository(SpellDefinition::class)->find($inscribedSpellId);
            if (!$inscribedSpell instanceof SpellDefinition) {
                throw new \InvalidArgumentException('Sort introuvable dans le catalogue.');
            }
        } elseif ($inscribedSpellId !== null) {
            throw new \InvalidArgumentException('Cet objet ne peut pas contenir un sort variable.');
        }

        // Regrouper les objets empilables déjà possédés.
        if (!$isScroll && $definition->getQuantityMode() === 'stackable') {
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
        $individual = $isScroll || $definition->getQuantityMode() !== 'stackable';
        $created = [];

        for ($i = 0; $i < ($individual ? $quantity : 1); $i++) {
            $owned = new CharacterMagicItem($character, $definition);

            if (!$individual) {
                $owned->setQuantity($quantity);
            }

            if ($inscribedSpell !== null) {
                $owned->setInscribedSpell($inscribedSpell);
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

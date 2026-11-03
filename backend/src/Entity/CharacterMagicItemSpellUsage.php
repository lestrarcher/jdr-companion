<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Remaining limited uses of one grant on one owned physical item. */
#[ORM\Entity]
#[ORM\Table(name: 'character_magic_item_spell_usage')]
#[ORM\UniqueConstraint(name: 'uniq_owned_item_spell_usage', columns: ['character_magic_item_id', 'magic_item_spell_grant_id'])]
class CharacterMagicItemSpellUsage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CharacterMagicItem::class)]
    #[ORM\JoinColumn(name: 'character_magic_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CharacterMagicItem $ownedItem;

    #[ORM\ManyToOne(targetEntity: MagicItemSpellGrant::class)]
    #[ORM\JoinColumn(name: 'magic_item_spell_grant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private MagicItemSpellGrant $grant;

    #[ORM\Column]
    private int $remaining;

    public function __construct(CharacterMagicItem $ownedItem, MagicItemSpellGrant $grant)
    {
        if ($ownedItem->getMagicItem() !== $grant->getMagicItem() || $grant->getCostMode() !== 'limited') {
            throw new \InvalidArgumentException('Octroi incompatible avec cet exemplaire.');
        }
        if ($ownedItem->getQuantity() !== 1) {
            throw new \InvalidArgumentException('Les sorts à utilisations limitées exigent un exemplaire individuel.');
        }
        $this->ownedItem = $ownedItem;
        $this->grant = $grant;
        $this->remaining = $grant->getUsesPerReset();
    }

    public function getId(): ?int { return $this->id; }
    public function getOwnedItem(): CharacterMagicItem { return $this->ownedItem; }
    public function getGrant(): MagicItemSpellGrant { return $this->grant; }
    public function getRemaining(): int { return $this->remaining; }

    public function spend(): void
    {
        $cost = $this->grant->getCost();
        if ($this->remaining < $cost) {
            throw new \DomainException('Utilisations insuffisantes.');
        }
        $this->remaining -= $cost;
    }

    public function reset(): void
    {
        $this->remaining = $this->grant->getUsesPerReset();
    }
}

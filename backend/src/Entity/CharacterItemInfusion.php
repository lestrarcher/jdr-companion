<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Lifecycle writes use ItemInfusions and database guards, like acquired feature choices. */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'character_item_infusion')]
#[ORM\Index(name: 'idx_item_infusion_creator', columns: ['creator_id', 'created_at'])]
#[ORM\UniqueConstraint(name: 'uniq_item_infusion_item', columns: ['item_id'], options: ['where' => 'active'])]
#[ORM\UniqueConstraint(name: 'uniq_item_infusion_choice', columns: ['choice_key'], options: ['where' => 'active'])]
class CharacterItemInfusion
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $creator;

    #[ORM\ManyToOne(targetEntity: CharacterMagicItem::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CharacterMagicItem $item;

    #[ORM\Column(length: 36)]
    private string $choiceKey;

    #[ORM\Column(length: 200)]
    private string $itemName;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $endReason = null;

    public function getId(): ?int { return $this->id; }
    public function getCreator(): Character { return $this->creator; }
    public function getItem(): ?CharacterMagicItem { return $this->item; }
    public function getChoiceKey(): string { return $this->choiceKey; }
    public function isActive(): bool { return $this->active; }
}

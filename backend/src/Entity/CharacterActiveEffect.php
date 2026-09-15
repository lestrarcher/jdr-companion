<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CharacterActiveEffectRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CharacterActiveEffectRepository::class)]
#[ORM\Table(name: 'character_active_effect')]
#[ORM\UniqueConstraint(
    name: 'uniq_character_active_effect_target_type',
    columns: ['target_character_id', 'effect_type'],
)]
class CharacterActiveEffect
{
    public const TYPE_AID = 'aid';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'target_character_id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private Character $targetCharacter;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'source_character_id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private Character $sourceCharacter;

    #[ORM\Column(
        name: 'effect_type',
        length: 50,
    )]
    private string $type;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Character $targetCharacter,
        Character $sourceCharacter,
        string $type,
        int $amount,
    ) {
        $this->targetCharacter = $targetCharacter;
        $this->sourceCharacter = $sourceCharacter;
        $this->type = $type;
        $this->amount = $amount;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTargetCharacter(): Character
    {
        return $this->targetCharacter;
    }

    public function getSourceCharacter(): Character
    {
        return $this->sourceCharacter;
    }

    public function setSourceCharacter(
        Character $sourceCharacter,
    ): void {
        $this->sourceCharacter = $sourceCharacter;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): void
    {
        $this->amount = $amount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

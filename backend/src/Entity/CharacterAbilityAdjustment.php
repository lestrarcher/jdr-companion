<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Ability;
use App\Enum\AbilityAdjustmentOperation;
use App\Enum\AbilityAdjustmentSource;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'character_ability_adjustment')]
class CharacterAbilityAdjustment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'abilityAdjustments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $character;

    #[ORM\Column(enumType: Ability::class)]
    private Ability $ability;

    #[ORM\Column(enumType: AbilityAdjustmentOperation::class)]
    private AbilityAdjustmentOperation $operation;

    #[ORM\Column]
    private int $value;

    #[ORM\Column(enumType: AbilityAdjustmentSource::class)]
    private AbilityAdjustmentSource $source;

    #[ORM\Column(length: 150)]
    private string $label;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    private ?int $acquiredAtLevel = null;

    #[ORM\Column]
    private int $displayOrder = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Character $character,
        Ability $ability,
        AbilityAdjustmentOperation $operation,
        int $value,
        AbilityAdjustmentSource $source,
        string $label,
        ?int $acquiredAtLevel = null,
        int $displayOrder = 0,
    ) {
        if ($operation === AbilityAdjustmentOperation::Set && ($value < 1 || $value > 30)) {
            throw new \InvalidArgumentException('Une caractéristique doit être fixée entre 1 et 30.');
        }

        if ($operation === AbilityAdjustmentOperation::Increase && $value === 0) {
            throw new \InvalidArgumentException('Un ajustement ne peut pas être égal à zéro.');
        }

        if ($acquiredAtLevel !== null && ($acquiredAtLevel < 1 || $acquiredAtLevel > 20)) {
            throw new \InvalidArgumentException('Le niveau d’acquisition doit être compris entre 1 et 20.');
        }

        $this->character = $character;
        $this->ability = $ability;
        $this->operation = $operation;
        $this->value = $value;
        $this->source = $source;
        $this->label = trim($label);
        $this->acquiredAtLevel = $acquiredAtLevel;
        $this->displayOrder = $displayOrder;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function getAbility(): Ability
    {
        return $this->ability;
    }

    public function getOperation(): AbilityAdjustmentOperation
    {
        return $this->operation;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function getSource(): AbilityAdjustmentSource
    {
        return $this->source;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description !== null ? trim($description) : null;

        return $this;
    }

    public function getAcquiredAtLevel(): ?int
    {
        return $this->acquiredAtLevel;
    }

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function apply(int $score): int
    {
        return match ($this->operation) {
            AbilityAdjustmentOperation::Increase => $score + $this->value,
            AbilityAdjustmentOperation::Set => $this->value,
        };
    }
}

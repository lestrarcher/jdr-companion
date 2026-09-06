<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Ability;
use App\Enum\AbilityEffectOperation;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'magic_item_ability_effect')]
class MagicItemAbilityEffect
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(
        inversedBy: 'abilityEffects',
        targetEntity: MagicItem::class,
    )]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private MagicItem $magicItem;

    #[ORM\Column(
        type: 'string',
        length: 20,
        enumType: Ability::class,
    )]
    private Ability $ability;

    #[ORM\Column(
        type: 'string',
        length: 30,
        enumType: AbilityEffectOperation::class,
    )]
    private AbilityEffectOperation $operation;

    /**
     * Bonus, minimum imposé ou augmentation permanente.
     */
    #[ORM\Column]
    private int $value;

    /**
     * Limite appliquée au bonus temporaire.
     *
     * Exemple : une Pierre ioun possède
     * un scoreCap de 20.
     */
    #[ORM\Column(nullable: true)]
    private ?int $scoreCap = null;

    /**
     * Augmentation permanente du maximum.
     *
     * Exemple : un Tome ajoute 2 à la valeur
     * et 2 au maximum.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $maximumIncrease = 0;

    public function __construct(
        MagicItem $magicItem,
        Ability $ability,
        AbilityEffectOperation $operation,
        int $value,
    ) {
        $this->magicItem = $magicItem;
        $this->ability = $ability;
        $this->operation = $operation;

        $this->setValue($value);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMagicItem(): MagicItem
    {
        return $this->magicItem;
    }

    public function getAbility(): Ability
    {
        return $this->ability;
    }

    public function getOperation():
        AbilityEffectOperation {
        return $this->operation;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function setValue(
        int $value,
    ): static {
        if (
            $this->operation
            === AbilityEffectOperation::Minimum
            && $value < 1
        ) {
            throw new \InvalidArgumentException(
                'Une valeur minimale doit être supérieure à zéro.',
            );
        }

        if (
            $value < -30
            || $value > 30
        ) {
            throw new \InvalidArgumentException(
                'La valeur de l’effet doit être comprise entre -30 et 30.',
            );
        }

        $this->value = $value;

        return $this;
    }

    public function getScoreCap(): ?int
    {
        return $this->scoreCap;
    }

    public function setScoreCap(
        ?int $scoreCap,
    ): static {
        if (
            $scoreCap !== null
            && (
                $scoreCap < 1
                || $scoreCap > 30
            )
        ) {
            throw new \InvalidArgumentException(
                'La limite doit être comprise entre 1 et 30.',
            );
        }

        $this->scoreCap = $scoreCap;

        return $this;
    }

    public function getMaximumIncrease(): int
    {
        return $this->maximumIncrease;
    }

    public function setMaximumIncrease(
        int $maximumIncrease,
    ): static {
        if (
            $maximumIncrease < 0
            || $maximumIncrease > 30
        ) {
            throw new \InvalidArgumentException(
                'L’augmentation du maximum doit être comprise entre 0 et 30.',
            );
        }

        $this->maximumIncrease =
            $maximumIncrease;

        return $this;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Ability;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'character_ability_score')]
#[ORM\UniqueConstraint(
    name: 'uniq_character_ability',
    columns: [
        'character_id',
        'ability',
    ],
)]
class CharacterAbilityScore
{
    private const MINIMUM_SCORE = 1;
    private const MAXIMUM_SCORE = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(
        inversedBy: 'abilityScores',
        targetEntity: Character::class,
    )]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private Character $character;

    #[ORM\Column(
        type: 'string',
        length: 20,
        enumType: Ability::class,
    )]
    private Ability $ability;

    #[ORM\Column]
    private int $baseValue;

    #[ORM\Column(options: ['default' => 20])]
    private int $maximumValue = 20;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Character $character,
        Ability $ability,
        int $baseValue = 10,
        int $maximumValue = 20,
    ) {
        $this->character = $character;
        $this->ability = $ability;
        $this->maximumValue =
            $this->validateMaximum(
                $maximumValue,
            );

        $this->baseValue =
            $this->validateBaseValue(
                $baseValue,
                $this->maximumValue,
            );

        $this->updatedAt =
            new DateTimeImmutable();
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

    public function getBaseValue(): int
    {
        return $this->baseValue;
    }

    public function setBaseValue(
        int $baseValue,
    ): static {
        $this->baseValue =
            $this->validateBaseValue(
                $baseValue,
                $this->maximumValue,
            );

        $this->touch();

        return $this;
    }

    public function getMaximumValue(): int
    {
        return $this->maximumValue;
    }

    public function setMaximumValue(
        int $maximumValue,
    ): static {
        $validatedMaximum =
            $this->validateMaximum(
                $maximumValue,
            );

        if (
            $validatedMaximum
            < $this->baseValue
        ) {
            throw new \InvalidArgumentException(
                'Le maximum ne peut pas être inférieur à la valeur de base.',
            );
        }

        $this->maximumValue =
            $validatedMaximum;

        $this->touch();

        return $this;
    }

    public function getModifier(): int
    {
        return (int) floor(
            ($this->baseValue - 10) / 2,
        );
    }

    public function getUpdatedAt():
        DateTimeImmutable {
        return $this->updatedAt;
    }

    private function validateBaseValue(
        int $baseValue,
        int $maximumValue,
    ): int {
        if (
            $baseValue
            < self::MINIMUM_SCORE
            || $baseValue
            > $maximumValue
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'La caractéristique doit être comprise entre %d et %d.',
                    self::MINIMUM_SCORE,
                    $maximumValue,
                ),
            );
        }

        return $baseValue;
    }

    private function validateMaximum(
        int $maximumValue,
    ): int {
        if (
            $maximumValue
            < self::MINIMUM_SCORE
            || $maximumValue
            > self::MAXIMUM_SCORE
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Le maximum doit être compris entre %d et %d.',
                    self::MINIMUM_SCORE,
                    self::MAXIMUM_SCORE,
                ),
            );
        }

        return $maximumValue;
    }

    private function touch(): void
    {
        $this->updatedAt =
            new DateTimeImmutable();
    }
}

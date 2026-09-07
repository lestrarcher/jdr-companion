<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Ability;
use App\Repository\CharacterFeatRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(
    repositoryClass: CharacterFeatRepository::class,
)]
#[ORM\Table(name: 'character_feat')]
class CharacterFeat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(
        targetEntity: Character::class,
        inversedBy: 'feats',
    )]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private Character $character;

    #[ORM\ManyToOne(
        targetEntity: Feat::class,
    )]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private Feat $feat;

    #[ORM\Column(
        enumType: Ability::class,
        nullable: true,
    )]
    private ?Ability $chosenAbility = null;

    /**
     * Niveau total auquel le don a été acquis.
     *
     * null permet d’importer un personnage ancien
     * lorsque cette information est inconnue.
     */
    #[ORM\Column(nullable: true)]
    private ?int $acquiredAtLevel = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Character $character,
        Feat $feat,
    ) {
        $this->character = $character;
        $this->feat = $feat;
        $this->createdAt =
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

    public function getFeat(): Feat
    {
        return $this->feat;
    }

    public function getChosenAbility(): ?Ability
    {
        return $this->chosenAbility;
    }

    public function setChosenAbility(?Ability $chosenAbility): static
    {
        if ($chosenAbility !== null && !$this->feat->requiresAbilityChoice())
        {
            throw new \InvalidArgumentException(
                'Ce don ne demande pas de choix de caractéristique.',
            );
        }

        if ($chosenAbility !== null && !$this->feat->allowsAbility($chosenAbility))
        {
            throw new \InvalidArgumentException(
                sprintf('La caractéristique %s n’est pas autorisée pour le don %s.', $chosenAbility->label(), $this->feat->getName())
            );
        }

        $this->chosenAbility = $chosenAbility;

        return $this;
    }

    public function hasRequiredAbilityChoice(): bool
    {
        return !$this->feat
            ->requiresAbilityChoice()
            || $this->chosenAbility !== null;
    }

    public function getAbilityIncrease(): int
    {
        if ($this->chosenAbility === null) {
            return 0;
        }

        return $this->feat
            ->getChosenAbilityIncrease();
    }

    public function getAcquiredAtLevel(): ?int
    {
        return $this->acquiredAtLevel;
    }

    public function setAcquiredAtLevel(
        ?int $level,
    ): static {
        if (
            $level !== null
            && ($level < 1 || $level > 20)
        ) {
            throw new \InvalidArgumentException(
                'Le niveau d’acquisition du don doit être compris entre 1 et 20.',
            );
        }

        $this->acquiredAtLevel = $level;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

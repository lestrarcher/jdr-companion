<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'character_class_level')]
#[ORM\UniqueConstraint(
    name: 'uniq_character_level_position',
    columns: ['character_id', 'position'],
)]
class CharacterClassLevel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'classLevels')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $character;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private CharacterClass $characterClass;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?CharacterSubclass $subclass = null;

    #[ORM\Column]
    private int $position;

    #[ORM\Column]
    private \DateTimeImmutable $acquiredAt;

    public function __construct(
        Character $character,
        CharacterClass $characterClass,
        int $position,
        ?CharacterSubclass $subclass = null,
    ) {
        if ($position < 1 || $position > 20) {
            throw new \InvalidArgumentException('La position du niveau doit être comprise entre 1 et 20.');
        }

        if ($subclass !== null && $subclass->getCharacterClass() !== $characterClass) {
            throw new \InvalidArgumentException('Cette sous-classe n’appartient pas à la classe sélectionnée.');
        }

        $this->character = $character;
        $this->characterClass = $characterClass;
        $this->position = $position;
        $this->subclass = $subclass;
        $this->acquiredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function getCharacterClass(): CharacterClass
    {
        return $this->characterClass;
    }

    public function getSubclass(): ?CharacterSubclass
    {
        return $this->subclass;
    }

    public function setSubclass(?CharacterSubclass $subclass): self
    {
        if ($subclass !== null && $subclass->getCharacterClass() !== $this->characterClass) {
            throw new \InvalidArgumentException('Cette sous-classe n’appartient pas à la classe sélectionnée.');
        }

        $this->subclass = $subclass;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getAcquiredAt(): \DateTimeImmutable
    {
        return $this->acquiredAt;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CharacterActionClassRuleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(
    repositoryClass: CharacterActionClassRuleRepository::class,
)]
#[ORM\Table(name: 'character_action_class_rule')]
#[ORM\UniqueConstraint(
    name: 'uniq_character_action_class',
    columns: [
        'action_definition_id',
        'character_class_id',
    ],
)]
class CharacterActionClassRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private CharacterActionDefinition $actionDefinition;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private CharacterClass $characterClass;

    #[ORM\Column]
    private int $unlockLevel;

    public function __construct(
        CharacterActionDefinition $actionDefinition,
        CharacterClass $characterClass,
        int $unlockLevel,
    ) {
        $this->actionDefinition = $actionDefinition;
        $this->characterClass = $characterClass;
        $this->setUnlockLevel($unlockLevel);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActionDefinition(): CharacterActionDefinition
    {
        return $this->actionDefinition;
    }

    public function getCharacterClass(): CharacterClass
    {
        return $this->characterClass;
    }

    public function getUnlockLevel(): int
    {
        return $this->unlockLevel;
    }

    public function setUnlockLevel(int $unlockLevel): static
    {
        if ($unlockLevel < 1 || $unlockLevel > 20) {
            throw new \InvalidArgumentException(
                'Le niveau de déblocage doit être compris entre 1 et 20.',
            );
        }

        $this->unlockLevel = $unlockLevel;

        return $this;
    }
}

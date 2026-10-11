<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReferenceOrigin;
use App\Repository\CharacterActionClassRuleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(
    repositoryClass: CharacterActionClassRuleRepository::class,
)]
#[ORM\Table(name: 'character_action_class_rule')]
#[ORM\UniqueConstraint(
    name: 'uniq_character_action_class_off',
    columns: ['action_definition_id', 'character_class_id'],
    options: ['where' => "((origin)::text = 'OFFICIAL'::text)"],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_character_action_class_own',
    columns: ['owner_id', 'action_definition_id', 'character_class_id'],
    options: ['where' => "((origin)::text = 'CUSTOM'::text)"],
)]
#[ORM\Index(name: 'idx_character_action_class_rule_owner', columns: ['owner_id'])]
#[ORM\HasLifecycleCallbacks]
class CharacterActionClassRule
{
    // Existing constructors and factories create official references only.
    #[ORM\Column(length: 8, enumType: ReferenceOrigin::class, options: ['default' => 'OFFICIAL'])]
    private ReferenceOrigin $origin = ReferenceOrigin::Official;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $owner = null;

    public function getOrigin(): ReferenceOrigin
    {
        return $this->origin;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    // No independent setters: custom creation is deliberately not exposed yet.
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    #[ORM\PostLoad]
    public function validateReferenceOwnership(): void
    {
        if (($this->origin === ReferenceOrigin::Official) !== ($this->owner === null)) {
            throw new \LogicException('Invalid reference origin/owner pair.');
        }
    }

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

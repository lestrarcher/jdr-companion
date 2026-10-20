<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\ReferenceVisibility;
use Doctrine\ORM\Mapping as ORM;

/** List membership only; never grants knowledge, preparation or a casting action. */
#[ORM\Entity]
#[ORM\Table(name: 'spell_class_list_entry')]
#[ORM\UniqueConstraint(name: 'uniq_spell_class_list', columns: ['spell_id', 'character_class_id'])]
#[ORM\Index(name: 'idx_spell_list_spell', columns: ['spell_id'])]
#[ORM\Index(name: 'idx_spell_list_class', columns: ['character_class_id'])]
#[ORM\HasLifecycleCallbacks]
class SpellClassListEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SpellDefinition::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private SpellDefinition $spell;

    #[ORM\ManyToOne(targetEntity: CharacterClass::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private CharacterClass $characterClass;

    public function __construct(SpellDefinition $spell, CharacterClass $characterClass)
    {
        $this->spell = $spell; $this->characterClass = $characterClass;
        $this->validateVisibility();
    }

    public function getId(): ?int { return $this->id; }
    public function getSpell(): SpellDefinition { return $this->spell; }
    public function getCharacterClass(): CharacterClass { return $this->characterClass; }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    #[ORM\PostLoad]
    public function validateVisibility(): void
    {
        if (!ReferenceVisibility::allows($this->characterClass, $this->spell->getOwner())) {
            throw new \InvalidArgumentException('Classe incompatible avec la propriété du sort.');
        }
    }
}

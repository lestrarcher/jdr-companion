<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** A spell written in one physical, individually tracked spellbook. */
#[ORM\Entity]
#[ORM\Table(name: 'spellbook_entry')]
#[ORM\UniqueConstraint(name: 'uniq_spellbook_spell', columns: ['owned_item_id', 'spell_id'])]
class SpellbookEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CharacterMagicItem::class)]
    #[ORM\JoinColumn(name: 'owned_item_id', nullable: false, onDelete: 'CASCADE')]
    private CharacterMagicItem $spellbook;

    #[ORM\ManyToOne(targetEntity: SpellDefinition::class)]
    #[ORM\JoinColumn(name: 'spell_id', nullable: false, onDelete: 'RESTRICT')]
    private SpellDefinition $spell;

    #[ORM\Column]
    private DateTimeImmutable $writtenAt;

    public function __construct(CharacterMagicItem $spellbook, SpellDefinition $spell)
    {
        if ($spellbook->getMagicItem()?->getCategory() !== 'spellbook') {
            throw new \InvalidArgumentException('Cet objet n’est pas un grimoire.');
        }
        if ($spellbook->getQuantity() !== 1) {
            throw new \InvalidArgumentException('Un grimoire doit être suivi individuellement.');
        }
        if ($spell->getLevel() === 0) {
            throw new \InvalidArgumentException('Les sorts mineurs ne sont pas inscrits dans un grimoire.');
        }
        $this->spellbook = $spellbook;
        $this->spell = $spell;
        $this->writtenAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSpellbook(): CharacterMagicItem { return $this->spellbook; }
    public function getSpell(): SpellDefinition { return $this->spell; }
    public function getWrittenAt(): DateTimeImmutable { return $this->writtenAt; }
}

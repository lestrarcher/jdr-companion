<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A physical Book of Shadows inscription; never a wizard acquisition. */
#[ORM\Entity]
#[ORM\Table(name: 'pact_tome_entry')]
#[ORM\UniqueConstraint(name: 'uniq_pact_tome_spell', columns: ['owned_item_id', 'spell_id'])]
#[ORM\Index(name: 'IDX_PACT_TOME_SPELL', columns: ['spell_id'])]
class PactTomeEntry
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CharacterMagicItem::class)]
    #[ORM\JoinColumn(name: 'owned_item_id', nullable: false, onDelete: 'CASCADE')]
    private CharacterMagicItem $book;

    #[ORM\ManyToOne(targetEntity: SpellDefinition::class)]
    #[ORM\JoinColumn(name: 'spell_id', nullable: false, onDelete: 'RESTRICT')]
    private SpellDefinition $spell;

    #[ORM\Column(length: 16)]
    private string $kind;

    public function __construct(CharacterMagicItem $book, SpellDefinition $spell, string $kind)
    {
        if ($book->getMagicItem()?->getCategory() !== 'spellbook' || $book->getQuantity() !== 1
            || !in_array($kind, ['cantrip', 'ritual'], true)
            || ($kind === 'cantrip' && $spell->getLevel() !== 0)
            || ($kind === 'ritual' && ($spell->getLevel() < 1 || !$spell->isRitual()))) {
            throw new \InvalidArgumentException('Inscription de pacte invalide.');
        }
        $this->book = $book;
        $this->spell = $spell;
        $this->kind = $kind;
    }

    public function getId(): ?int { return $this->id; }
    public function getBook(): CharacterMagicItem { return $this->book; }
    public function getSpell(): SpellDefinition { return $this->spell; }
    public function getKind(): string { return $this->kind; }
}

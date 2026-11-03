<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use App\Entity\SpellDefinition;
use App\Repository\CharacterMagicItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CharacterMagicItemRepository::class)]
#[ORM\Table(name: 'character_magic_item')]
class CharacterMagicItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Campaign $campaign;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $displayName = null;

    #[ORM\ManyToOne(
        inversedBy: 'magicItems',
        targetEntity: Character::class,
    )]
    #[ORM\JoinColumn(
        nullable: true,
        onDelete: 'SET NULL',
    )]
    private ?Character $character;

    #[ORM\ManyToOne(
        targetEntity: MagicItem::class,
    )]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private MagicItem $magicItem;

    #[ORM\ManyToOne(targetEntity: SpellDefinition::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?SpellDefinition $inscribedSpell = null;

    public function getInscribedSpell(): ?SpellDefinition { return $this->inscribedSpell; }

    public function setInscribedSpell(SpellDefinition $spell): static
    {
        $this->inscribedSpell = $spell;
        $this->touch();
        return $this;
    }

    #[ORM\Column(options: ['default' => 1])]
    private int $quantity = 1;

    #[ORM\Column(nullable: true)]
    private ?int $currentCharges = null;

    #[ORM\Column]
    private bool $attuned = false;

    #[ORM\Column]
    private bool $equipped = false;

    // Reserved for structured equipment; legacy equipped items occupy no inferred slot.
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $equippedSlot = null;

    #[ORM\Column(nullable: true)]
    private ?int $handsUsed = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $legacyStack = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private DateTimeImmutable $acquiredAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Character|Campaign $character,
        MagicItem $magicItem,
    ) {
        $campaign = $character instanceof Character ? $character->getCampaign() : $character;
        if ($character instanceof Campaign && ($magicItem->getCategory() !== 'spellbook' || $magicItem->getQuantityMode() !== 'individual')) {
            throw new \InvalidArgumentException('Seul un grimoire individuel peut être créé dans la réserve.');
        }
        if (
            $magicItem->getCampaign() !== null
            && $campaign->getId() !== $magicItem->getCampaign()->getId()
        ) {
            throw new \InvalidArgumentException(
                'Cet objet appartient à une autre campagne.',
            );
        }

        $this->character = $character instanceof Character ? $character : null;
        $this->campaign = $campaign;
        $this->magicItem = $magicItem;
        $this->currentCharges =
            $magicItem->getMaximumCharges();

        $now = new DateTimeImmutable();

        $this->acquiredAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): Campaign { return $this->campaign; }

    public function getDisplayName(): string { return $this->displayName ?? $this->magicItem->getName(); }

    public function setDisplayName(?string $name): static
    {
        $name = $name === null ? null : trim($name);
        if ($name !== null && mb_strlen($name) > 200) {
            throw new \InvalidArgumentException('Nom trop long.');
        }
        $this->displayName = $name ?: null;
        $this->touch();
        return $this;
    }

    public function transferTo(?Character $character): static
    {
        if ($this->magicItem->getCategory() !== 'spellbook') {
            throw new \InvalidArgumentException('Seuls les grimoires peuvent être transférés vers la réserve.');
        }
        if ($character !== null && $character->getCampaign()->getId() !== $this->campaign->getId()) {
            throw new \InvalidArgumentException('Le destinataire appartient à une autre campagne.');
        }
        $this->character = $character;
        $this->equipped = false;
        $this->attuned = false;
        $this->equippedSlot = null;
        $this->handsUsed = null;
        $this->touch();
        return $this;
    }

    public function getCharacter(): ?Character
    {
        return $this->character;
    }

    public function getMagicItem(): MagicItem
    {
        return $this->magicItem;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getEquippedSlot(): ?string { return $this->equippedSlot; }
    public function getHandsUsed(): ?int { return $this->handsUsed; }
    public function isLegacyStack(): bool { return $this->legacyStack; }

    public function setQuantity(int $quantity): static
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException(
                'La quantité doit être supérieure à zéro.',
            );
        }

        if ($quantity > 1) {
            $mode = $this->magicItem->getQuantityMode();

            if ($mode === 'individual') {
                throw new \InvalidArgumentException(
                    'Cet objet doit être suivi individuellement.',
                );
            }

            if ($mode === 'legacy' && $this->magicItem->hasCharges()) {
                throw new \InvalidArgumentException(
                    'Les objets à charges doivent être suivis individuellement.',
                );
            }
        }

        $this->quantity = $quantity;
        $this->touch();

        return $this;
    }

    public function getCurrentCharges(): ?int
    {
        return $this->currentCharges;
    }

    public function setCurrentCharges(
        ?int $currentCharges,
    ): static {
        $maximumCharges =
            $this->magicItem
                ->getMaximumCharges();

        if ($maximumCharges === null) {
            if ($currentCharges !== null) {
                throw new \InvalidArgumentException(
                    'Cet objet ne possède pas de charges.',
                );
            }

            $this->currentCharges = null;
            $this->touch();

            return $this;
        }

        if (
            $currentCharges === null
            || $currentCharges < 0
            || $currentCharges
                > $maximumCharges
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Les charges doivent être comprises entre 0 et %d.',
                    $maximumCharges,
                ),
            );
        }

        $this->currentCharges =
            $currentCharges;

        $this->touch();

        return $this;
    }

    public function adjustCharges(
        int $change,
    ): static {
        if (
            $this->currentCharges === null
        ) {
            throw new \InvalidArgumentException(
                'Cet objet ne possède pas de charges.',
            );
        }

        return $this->setCurrentCharges(
            $this->currentCharges + $change,
        );
    }

    public function isAttuned(): bool
    {
        return $this->attuned;
    }

    public function setAttuned(
        bool $attuned,
    ): static {
        if (
            $attuned
            && !$this->magicItem
                ->requiresAttunement()
        ) {
            throw new \InvalidArgumentException(
                'Cet objet ne nécessite pas d’harmonisation.',
            );
        }

        $this->attuned = $attuned;
        $this->touch();

        return $this;
    }

    public function isEquipped(): bool
    {
        return $this->equipped;
    }

    public function setEquipped(
        bool $equipped,
    ): static {
        if ($equipped && !$this->magicItem->isEquippable()) {
            throw new \InvalidArgumentException('Cet objet ne peut pas être équipé.');
        }
        $this->equipped = $equipped;
        $this->touch();

        return $this;
    }

    public function isEffectActive(): bool
    {
        if (!$this->equipped) {
            return false;
        }

        return (
            !$this->magicItem
                ->requiresAttunement()
            || $this->attuned
        );
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(
        ?string $notes,
    ): static {
        $notes =
            $notes !== null
                ? trim($notes)
                : null;

        $this->notes =
            $notes !== ''
                ? $notes
                : null;

        $this->touch();

        return $this;
    }

    public function getAcquiredAt():
        DateTimeImmutable {
        return $this->acquiredAt;
    }

    public function getUpdatedAt():
        DateTimeImmutable {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt =
            new DateTimeImmutable();
    }
}

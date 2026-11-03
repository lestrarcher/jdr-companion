<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Spell permission attached to a catalogue definition, not to a character. */
#[ORM\Entity]
#[ORM\Table(name: 'magic_item_spell_grant')]
#[ORM\UniqueConstraint(name: 'uniq_magic_item_spell_grant', columns: ['magic_item_id', 'spell_id'])]
class MagicItemSpellGrant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MagicItem::class, inversedBy: 'spellGrants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MagicItem $magicItem;

    #[ORM\ManyToOne(targetEntity: SpellDefinition::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private SpellDefinition $spell;

    /** possession | equipped | attuned */
    #[ORM\Column(length: 16)]
    private string $activation;

    /** charges | limited. No spell-slot casting until supported by the executor. */
    #[ORM\Column(length: 16)]
    private string $costMode;

    #[ORM\Column]
    private int $cost;

    /** Fixed item casting level, or minimum level for variable-cost casts. */
    #[ORM\Column(nullable: true)]
    private ?int $castingLevel = null;

    /** Highest selectable level; null means fixed-level casting. */
    #[ORM\Column(nullable: true)]
    private ?int $maxCastingLevel = null;

    /** Number of casts between resets, only for limited grants. */
    #[ORM\Column(nullable: true)]
    private ?int $usesPerReset;

    /** dawn | short-rest | long-rest | manual; only for limited grants. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $resetTrigger;

    public function __construct(
        MagicItem $magicItem,
        SpellDefinition $spell,
        string $activation,
        string $costMode,
        int $cost = 1,
        ?int $usesPerReset = null,
        ?string $resetTrigger = null,
        ?int $castingLevel = null,
        ?int $maxCastingLevel = null,
    ) {
        if (!in_array($activation, ['possession', 'equipped', 'attuned'], true)) {
            throw new \InvalidArgumentException('Condition d’activation invalide.');
        }
        if ($activation === 'attuned' && !$magicItem->requiresAttunement()) {
            throw new \InvalidArgumentException('L’objet ne requiert pas d’harmonisation.');
        }
        if ($activation === 'equipped' && !$magicItem->isEquippable()) {
            throw new \InvalidArgumentException('L’objet ne peut pas être équipé.');
        }
        if (!in_array($costMode, ['charges', 'limited'], true) || $cost < 1) {
            throw new \InvalidArgumentException('Coût de lancement invalide.');
        }
        if ($costMode === 'charges') {
            if (!$magicItem->hasCharges() || $cost > $magicItem->getMaximumCharges() || $usesPerReset !== null || $resetTrigger !== null) {
                throw new \InvalidArgumentException('Réserve de charges incohérente.');
            }
        } elseif ($usesPerReset === null || $usesPerReset < 1 || $cost > $usesPerReset
            || !in_array($resetTrigger, ['dawn', 'short-rest', 'long-rest', 'manual'], true)) {
            throw new \InvalidArgumentException('Limite ou recharge de l’octroi invalide.');
        }
        $baseLevel = $spell->getLevel();
        $castingLevel ??= $baseLevel;
        if ($castingLevel < $baseLevel || $castingLevel > 9
            || ($maxCastingLevel !== null && ($costMode !== 'charges' || $baseLevel < 1
                || $maxCastingLevel <= $castingLevel || $maxCastingLevel > 9
                || $cost + $maxCastingLevel - $castingLevel > $magicItem->getMaximumCharges()))) {
            throw new \InvalidArgumentException('Niveau de lancement ou coût variable invalide.');
        }
        $this->castingLevel = $castingLevel;
        $this->maxCastingLevel = $maxCastingLevel;
        $this->magicItem = $magicItem;
        $this->spell = $spell;
        $this->activation = $activation;
        $this->costMode = $costMode;
        $this->cost = $cost;
        $this->usesPerReset = $usesPerReset;
        $this->resetTrigger = $resetTrigger;
    }

    public function getId(): ?int { return $this->id; }
    public function getMagicItem(): MagicItem { return $this->magicItem; }
    public function getSpell(): SpellDefinition { return $this->spell; }
    public function getActivation(): string { return $this->activation; }
    public function getCostMode(): string { return $this->costMode; }
    public function getCost(): int { return $this->cost; }
    public function getUsesPerReset(): ?int { return $this->usesPerReset; }
    public function getResetTrigger(): ?string { return $this->resetTrigger; }
    public function getCastingLevel(): int { return $this->castingLevel ?? $this->spell->getLevel(); }
    public function getMaxCastingLevel(): ?int { return $this->maxCastingLevel; }
    public function costAtLevel(int $level): int {
        if ($level < $this->getCastingLevel() || $level > ($this->maxCastingLevel ?? $this->getCastingLevel())) {
            throw new \InvalidArgumentException('Niveau de lancement non autorisé pour cet objet.');
        }
        return $this->cost + $level - $this->getCastingLevel();
    }
}

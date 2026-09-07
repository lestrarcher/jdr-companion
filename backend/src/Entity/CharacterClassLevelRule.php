<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LevelAdvancementChoice;
use App\Repository\CharacterClassLevelRuleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CharacterClassLevelRuleRepository::class)]
#[ORM\Table(name: 'character_class_level_rule')]
#[ORM\UniqueConstraint(
    name: 'uniq_character_class_level_rule',
    columns: ['character_class_id', 'level'],
)]
class CharacterClassLevelRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CharacterClass $characterClass;

    #[ORM\Column]
    private int $level;

    #[ORM\Column(enumType: LevelAdvancementChoice::class)]
    private LevelAdvancementChoice $advancementChoice;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function __construct(
        CharacterClass $characterClass,
        int $level,
        LevelAdvancementChoice $advancementChoice = LevelAdvancementChoice::None,
    ) {
        if ($level < 1 || $level > 20) {
            throw new \InvalidArgumentException('Le niveau de classe doit être compris entre 1 et 20.');
        }

        $this->characterClass = $characterClass;
        $this->level = $level;
        $this->advancementChoice = $advancementChoice;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacterClass(): CharacterClass
    {
        return $this->characterClass;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function getAdvancementChoice(): LevelAdvancementChoice
    {
        return $this->advancementChoice;
    }

    public function setAdvancementChoice(LevelAdvancementChoice $advancementChoice): self
    {
        $this->advancementChoice = $advancementChoice;

        return $this;
    }

    public function requiresAbilityScoreImprovementOrFeat(): bool
    {
        return $this->advancementChoice === LevelAdvancementChoice::AbilityScoreImprovementOrFeat;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes !== null ? trim($notes) : null;

        return $this;
    }
}

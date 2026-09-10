<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CharacterFeatureRuleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CharacterFeatureRuleRepository::class)]
#[ORM\Table(name: 'character_feature_rule')]
#[ORM\UniqueConstraint(
    name: 'uniq_class_feature_level',
    columns: ['character_class_id', 'feature_definition_id', 'unlock_level'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_subclass_feature_level',
    columns: ['character_subclass_id', 'feature_definition_id', 'unlock_level'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_race_feature_level',
    columns: ['character_race_id', 'feature_definition_id', 'unlock_level'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_feat_feature_level',
    columns: ['feat_id', 'feature_definition_id', 'unlock_level'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_progression_feature_threshold',
    columns: [
        'progression_definition_id',
        'feature_definition_id',
        'progression_threshold',
    ],
)]
class CharacterFeatureRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CharacterFeatureDefinition $featureDefinition;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?CharacterClass $characterClass = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?CharacterSubclass $characterSubclass = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?CharacterRace $characterRace = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Feat $feat = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ProgressionDefinition $progressionDefinition = null;

    #[ORM\Column(nullable: true)]
    private ?int $progressionThreshold = null;

    /**
     * Niveau dans la classe ou sous-classe concernée.
     *
     * Pour une race ou un don, cette valeur vaut généralement 1.
     */
    #[ORM\Column]
    private int $unlockLevel;

    #[ORM\Column(options: ['default' => 0])]
    private int $displayOrder = 0;

    private function __construct(
        CharacterFeatureDefinition $featureDefinition,
        int $unlockLevel,
        int $displayOrder = 0,
    ) {
        $this->featureDefinition = $featureDefinition;
        $this->setUnlockLevel($unlockLevel);
        $this->setDisplayOrder($displayOrder);
    }

    public static function forClass(
        CharacterFeatureDefinition $featureDefinition,
        CharacterClass $characterClass,
        int $unlockLevel,
        int $displayOrder = 0,
    ): self {
        $rule = new self($featureDefinition, $unlockLevel, $displayOrder);
        $rule->characterClass = $characterClass;

        return $rule;
    }

    public static function forSubclass(
        CharacterFeatureDefinition $featureDefinition,
        CharacterSubclass $characterSubclass,
        int $unlockLevel,
        int $displayOrder = 0,
    ): self {
        $rule = new self($featureDefinition, $unlockLevel, $displayOrder);
        $rule->characterSubclass = $characterSubclass;

        return $rule;
    }

    public static function forRace(
        CharacterFeatureDefinition $featureDefinition,
        CharacterRace $characterRace,
        int $unlockLevel = 1,
        int $displayOrder = 0,
    ): self {
        $rule = new self($featureDefinition, $unlockLevel, $displayOrder);
        $rule->characterRace = $characterRace;

        return $rule;
    }

    public static function forFeat(
        CharacterFeatureDefinition $featureDefinition,
        Feat $feat,
        int $unlockLevel = 1,
        int $displayOrder = 0,
    ): self {
        $rule = new self($featureDefinition, $unlockLevel, $displayOrder);
        $rule->feat = $feat;

        return $rule;
    }

    public static function forProgression(
        CharacterFeatureDefinition $featureDefinition,
        ProgressionDefinition $progressionDefinition,
        int $progressionThreshold,
        int $displayOrder = 0,
    ): self {
        $rule = new self(
            $featureDefinition,
            1,
            $displayOrder,
        );

        $rule->progressionDefinition =
            $progressionDefinition;
        $rule->setProgressionThreshold(
            $progressionThreshold,
        );

        return $rule;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFeatureDefinition(): CharacterFeatureDefinition
    {
        return $this->featureDefinition;
    }

    public function getCharacterClass(): ?CharacterClass
    {
        return $this->characterClass;
    }

    public function getCharacterSubclass(): ?CharacterSubclass
    {
        return $this->characterSubclass;
    }

    public function getCharacterRace(): ?CharacterRace
    {
        return $this->characterRace;
    }

    public function getFeat(): ?Feat
    {
        return $this->feat;
    }

    public function getProgressionDefinition(): ?ProgressionDefinition
    {
        return $this->progressionDefinition;
    }

    public function getProgressionThreshold(): ?int
    {
        return $this->progressionThreshold;
    }

    public function setProgressionThreshold(
        int $progressionThreshold,
    ): static {
        if ($progressionThreshold < 0) {
            throw new \InvalidArgumentException(
                'Le seuil de progression ne peut pas être négatif.',
            );
        }

        $this->progressionThreshold =
            $progressionThreshold;

        return $this;
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

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): static
    {
        if ($displayOrder < 0) {
            throw new \InvalidArgumentException(
                'L’ordre d’affichage ne peut pas être négatif.',
            );
        }

        $this->displayOrder = $displayOrder;

        return $this;
    }

    public function sourceType(): string
    {
        return match (true) {
            $this->characterClass !== null => 'class',
            $this->characterSubclass !== null => 'subclass',
            $this->characterRace !== null => 'race',
            $this->feat !== null => 'feat',
            $this->progressionDefinition !== null => 'progression',
            default => throw new \LogicException('La capacité ne possède aucune source.'),
        };
    }

    public function sourceId(): int
    {
        $id = match ($this->sourceType()) {
            'class' => $this->characterClass?->getId(),
            'subclass' => $this->characterSubclass?->getId(),
            'race' => $this->characterRace?->getId(),
            'feat' => $this->feat?->getId(),
            'progression' => $this->progressionDefinition?->getId(),
        };

        return $id ?? throw new \LogicException('La source de la capacité n’est pas persistée.');
    }

    public function sourceName(): string
    {
        return match ($this->sourceType()) {
            'class' => $this->characterClass->getName(),
            'subclass' => $this->characterSubclass->getName(),
            'race' => $this->characterRace->getName(),
            'feat' => $this->feat->getName(),
            'progression' => $this->progressionDefinition->getName(),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrackableResourceRuleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TrackableResourceRuleRepository::class)]
#[ORM\Table(name: 'trackable_resource_rule')]
#[ORM\UniqueConstraint(
    name: 'uniq_class_resource_level',
    columns: ['character_class_id', 'resource_definition_id', 'unlock_level'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_subclass_resource_level',
    columns: ['character_subclass_id', 'resource_definition_id', 'unlock_level'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_race_resource_level',
    columns: ['character_race_id', 'resource_definition_id', 'unlock_level'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_feat_resource_level',
    columns: ['feat_id', 'resource_definition_id', 'unlock_level'],
)]
class TrackableResourceRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TrackableResourceDefinition $resourceDefinition;

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

    /**
     * Niveau dans la classe ou la sous-classe concernée.
     *
     * Pour une race ou un don, cette valeur vaut généralement 1.
     */
    #[ORM\Column]
    private int $unlockLevel;

    /**
     * Permet de remplacer le maximum fixe à certains paliers.
     *
     * Exemple pour Rage :
     * niveau 1 => 2
     * niveau 3 => 3
     * niveau 6 => 4
     */
    #[ORM\Column(nullable: true)]
    private ?int $maximumOverride = null;

    private function __construct(
        TrackableResourceDefinition $resourceDefinition,
        int $unlockLevel,
        ?int $maximumOverride,
    ) {
        if ($unlockLevel < 1 || $unlockLevel > 20) {
            throw new \InvalidArgumentException(
                'Le niveau de déblocage doit être compris entre 1 et 20.',
            );
        }

        if ($maximumOverride !== null && $maximumOverride < 0) {
            throw new \InvalidArgumentException(
                'Le maximum d’une ressource ne peut pas être négatif.',
            );
        }

        $this->resourceDefinition = $resourceDefinition;
        $this->unlockLevel = $unlockLevel;
        $this->maximumOverride = $maximumOverride;
    }

    public static function forClass(
        TrackableResourceDefinition $resourceDefinition,
        CharacterClass $characterClass,
        int $unlockLevel,
        ?int $maximumOverride = null,
    ): self {
        $rule = new self($resourceDefinition, $unlockLevel, $maximumOverride);
        $rule->characterClass = $characterClass;

        return $rule;
    }

    public static function forSubclass(
        TrackableResourceDefinition $resourceDefinition,
        CharacterSubclass $characterSubclass,
        int $unlockLevel,
        ?int $maximumOverride = null,
    ): self {
        $rule = new self($resourceDefinition, $unlockLevel, $maximumOverride);
        $rule->characterSubclass = $characterSubclass;

        return $rule;
    }

    public static function forRace(
        TrackableResourceDefinition $resourceDefinition,
        CharacterRace $characterRace,
        int $unlockLevel = 1,
        ?int $maximumOverride = null,
    ): self {
        $rule = new self($resourceDefinition, $unlockLevel, $maximumOverride);
        $rule->characterRace = $characterRace;

        return $rule;
    }

    public static function forFeat(
        TrackableResourceDefinition $resourceDefinition,
        Feat $feat,
        int $unlockLevel = 1,
        ?int $maximumOverride = null,
    ): self {
        $rule = new self($resourceDefinition, $unlockLevel, $maximumOverride);
        $rule->feat = $feat;

        return $rule;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getResourceDefinition(): TrackableResourceDefinition
    {
        return $this->resourceDefinition;
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

    public function getUnlockLevel(): int
    {
        return $this->unlockLevel;
    }

    public function getMaximumOverride(): ?int
    {
        return $this->maximumOverride;
    }

    public function setMaximumOverride(?int $maximumOverride): self
    {
        if ($maximumOverride !== null && $maximumOverride < 0) {
            throw new \InvalidArgumentException(
                'Le maximum d’une ressource ne peut pas être négatif.',
            );
        }

        $this->maximumOverride = $maximumOverride;

        return $this;
    }
}
